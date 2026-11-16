param([string]$Root,[string]$Manifest,[string]$Prefix,[int]$Concurrency=16,[int]$BatchSize=500,[int]$FlushSeconds=120,[string]$TargetFile)
Add-Type -TypeDefinition @"
using System;
using System.IO;
using System.IO.Compression;
using System.Text;
public sealed class EgsRequestGate {
 private readonly object gate=new object();
 private readonly int maximum;
 private readonly string targetFile;
 private int active=0,ceiling,failures=0,successes=0,attempts=0,errors=0,limited=0;
 private DateTime recoverAfter=DateTime.MinValue,blockedUntil=DateTime.MinValue;
 public EgsRequestGate(int maximum,string targetFile){this.maximum=maximum;ceiling=maximum;this.targetFile=targetFile;}
 private int Target(){int target=maximum;try{if(!String.IsNullOrEmpty(targetFile)&&File.Exists(targetFile))target=Int32.Parse(File.ReadAllText(targetFile).Trim());}catch{}return Math.Max(1,Math.Min(ceiling,Math.Min(maximum,target)));}
 public void Enter(){lock(gate){while(active>=Target()||DateTime.UtcNow<blockedUntil)System.Threading.Monitor.Wait(gate,500);active++;attempts++;}}
 public void Pause(int seconds){lock(gate){var until=DateTime.UtcNow.AddSeconds(seconds);if(until>blockedUntil)blockedUntil=until;}}
 public void Leave(int status){lock(gate){active--;if(status==429){limited++;ceiling=Math.Max(1,maximum*2/3);recoverAfter=DateTime.UtcNow.AddMinutes(2);successes=0;}if(status==0||status==429||status>=500){errors++;failures++;if(failures>=3){ceiling=Math.Max(1,maximum*2/3);recoverAfter=DateTime.UtcNow.AddMinutes(2);successes=0;}}else{failures=0;successes++;if(successes>=64&&DateTime.UtcNow>=recoverAfter)ceiling=maximum;}System.Threading.Monitor.PulseAll(gate);try{if(!String.IsNullOrEmpty(targetFile))File.WriteAllText(targetFile+".health.json",Stats());}catch{}}}
 public string Stats(){lock(gate){return "{\"attempts\":"+attempts+",\"transient_errors\":"+errors+",\"rate_limits\":"+limited+",\"current_limit\":"+Target()+"}";}}
}
public sealed class EgsCaptureArchive : IDisposable {
 private readonly object gate=new object();
 private readonly string root,prefix;
 private ZipArchive archive;
 private FileStream stream;
 private StringBuilder index=new StringBuilder();
 private int part=0,count=0;
 private readonly int batchSize,flushSeconds;
 private readonly System.Threading.Timer timer;
 private DateTime openedAt;
 private bool disposed;
 private Exception fault;
 private string openName;
 public EgsCaptureArchive(string root,string prefix,int batchSize,int flushSeconds){if(batchSize<1||flushSeconds<1)throw new ArgumentException("Invalid batch limits");this.root=root;this.prefix=prefix;this.batchSize=batchSize;this.flushSeconds=flushSeconds;timer=new System.Threading.Timer(_=>FlushExpired(),null,1000,1000);}
 private void FlushExpired(){lock(gate){if(disposed||archive==null||count==0)return;try{if((DateTime.UtcNow-openedAt).TotalSeconds>=flushSeconds)ClosePart();}catch(Exception ex){fault=ex;}}}
 private void ClosePart(){
  if(archive==null)return;
  var metadata=archive.CreateEntry("_capture-"+prefix+"-"+part.ToString("D4")+".jsonl",CompressionLevel.Fastest);
  using(var s=metadata.Open())using(var w=new StreamWriter(s,new UTF8Encoding(false))){w.Write(index.ToString());}
  archive.Dispose();stream.Dispose();
  File.Move(openName,Path.ChangeExtension(openName,"zip"));
  archive=null;stream=null;count=0;index.Clear();
 }
 public void Save(string name,byte[] bytes,string metadata){
  if(Path.GetFileName(name)!=name)throw new Exception("Unsafe capture filename");
  lock(gate){
   if(disposed)throw new ObjectDisposedException("EgsCaptureArchive");if(fault!=null)throw new IOException("Capture archive failed",fault);
   if(archive==null){openedAt=DateTime.UtcNow;part++;openName=Path.Combine(root,prefix+"-"+part.ToString("D4")+".part");stream=new FileStream(openName,FileMode.CreateNew,FileAccess.ReadWrite,FileShare.Read);archive=new ZipArchive(stream,ZipArchiveMode.Create,true);}
   var entry=archive.CreateEntry(name,CompressionLevel.Fastest);
   using(var s=entry.Open()){s.Write(bytes,0,bytes.Length);}
   index.AppendLine(metadata);count++;if(count>=batchSize)ClosePart();
  }
 }
 public void Dispose(){lock(gate){disposed=true;timer.Dispose();ClosePart();}}
}
"@
$requests=Get-Content (Join-Path $Root $Manifest) -Raw | ConvertFrom-Json
$handler=[System.Net.Http.HttpClientHandler]::new()
$handler.MaxConnectionsPerServer=$Concurrency
$handler.AutomaticDecompression=[System.Net.DecompressionMethods]::GZip -bor [System.Net.DecompressionMethods]::Deflate
$http=[System.Net.Http.HttpClient]::new($handler)
$http.Timeout=[TimeSpan]::FromSeconds(45)
$http.DefaultRequestHeaders.UserAgent.ParseAdd('PollmediaPublicRecords/1.0')
$writer=[EgsCaptureArchive]::new($Root,$Prefix,$BatchSize,$FlushSeconds)
$gate=[EgsRequestGate]::new($Concurrency,$TargetFile)
$timer=[System.Diagnostics.Stopwatch]::StartNew()
try {
 $results=$requests | ForEach-Object -Parallel {
  $client=$using:http;$archive=$using:writer;$requestGate=$using:gate;$task=$_
  for($attempt=0;$attempt -lt 3;$attempt++){
   $status=$null;$response=$null
   $requestGate.Enter()
   $requestTimer=[System.Diagnostics.Stopwatch]::StartNew()
   try{
    $response=$client.GetAsync($task.url).GetAwaiter().GetResult()
    $status=[int]$response.StatusCode
    if($status -eq 429){
     $wait=30;$retryAfter=$response.Headers.RetryAfter
     if($null -ne $retryAfter.Delta){$wait=[Math]::Max(30,[int]$retryAfter.Delta.TotalSeconds)}
     elseif($null -ne $retryAfter.Date){$wait=[Math]::Max(30,[int]($retryAfter.Date-[DateTimeOffset]::UtcNow).TotalSeconds)}
     $requestGate.Pause($wait)
    }
    if($status -eq 429 -or $status -ge 500){$response.Dispose();Start-Sleep -Seconds (5*($attempt+1));continue}
    $response.EnsureSuccessStatusCode() | Out-Null
    $bytes=$response.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
    $meta=@{file=$task.file;url=$task.url;http_status=$status;elapsed_ms=$requestTimer.ElapsedMilliseconds;captured_at=[DateTimeOffset]::UtcNow.ToString('O')} | ConvertTo-Json -Compress
    $archive.Save($task.file,$bytes,$meta)
    $response.Dispose()
    [pscustomobject]@{file=$task.file;ok=$true;bytes=$bytes.Length};return
   }catch{$last=$_.Exception.Message;if($status -eq 404){break}}finally{if($null -ne $response){$response.Dispose()};$requestGate.Leave($(if($null -eq $status){0}else{$status}))}
  }
  [pscustomobject]@{file=$task.file;ok=$false;error=$last;status=$status}
 } -ThrottleLimit $Concurrency
} finally {$writer.Dispose();$http.Dispose()}
ConvertTo-Json -InputObject @($results) -Depth 4 | Set-Content (Join-Path $Root "$Prefix-results.json")
$results | Group-Object ok | Select-Object Name,Count
Write-Output ($gate.Stats())
Write-Output "Elapsed seconds $($timer.Elapsed.TotalSeconds)"
