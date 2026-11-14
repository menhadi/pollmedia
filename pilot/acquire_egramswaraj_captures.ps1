param([string]$Root,[string]$Manifest,[string]$Prefix,[int]$Concurrency=16)
Add-Type -TypeDefinition @"
using System;
using System.IO;
using System.IO.Compression;
using System.Text;
public sealed class EgsCaptureArchive : IDisposable {
 private readonly object gate=new object();
 private readonly string root,prefix;
 private ZipArchive archive;
 private FileStream stream;
 private StringBuilder index=new StringBuilder();
 private int part=0,count=0;
 private string openName;
 public EgsCaptureArchive(string root,string prefix){this.root=root;this.prefix=prefix;}
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
   if(archive==null){part++;openName=Path.Combine(root,prefix+"-"+part.ToString("D4")+".part");stream=new FileStream(openName,FileMode.CreateNew,FileAccess.ReadWrite,FileShare.Read);archive=new ZipArchive(stream,ZipArchiveMode.Create,true);}
   var entry=archive.CreateEntry(name,CompressionLevel.Fastest);
   using(var s=entry.Open()){s.Write(bytes,0,bytes.Length);}
   index.AppendLine(metadata);count++;if(count>=500)ClosePart();
  }
 }
 public void Dispose(){lock(gate){ClosePart();}}
}
"@
$requests=Get-Content (Join-Path $Root $Manifest) -Raw | ConvertFrom-Json
$handler=[System.Net.Http.HttpClientHandler]::new()
$handler.MaxConnectionsPerServer=$Concurrency
$handler.AutomaticDecompression=[System.Net.DecompressionMethods]::GZip -bor [System.Net.DecompressionMethods]::Deflate
$http=[System.Net.Http.HttpClient]::new($handler)
$http.Timeout=[TimeSpan]::FromSeconds(45)
$http.DefaultRequestHeaders.UserAgent.ParseAdd('PollmediaPublicRecords/1.0')
$writer=[EgsCaptureArchive]::new($Root,$Prefix)
$timer=[System.Diagnostics.Stopwatch]::StartNew()
try {
 $results=$requests | ForEach-Object -Parallel {
  $client=$using:http;$archive=$using:writer;$task=$_
  for($attempt=0;$attempt -lt 3;$attempt++){
   try{
    $response=$client.GetAsync($task.url).GetAwaiter().GetResult()
    $status=[int]$response.StatusCode
    if($status -eq 429 -or $status -ge 500){$response.Dispose();Start-Sleep -Seconds (5*($attempt+1));continue}
    $response.EnsureSuccessStatusCode() | Out-Null
    $bytes=$response.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
    $meta=@{file=$task.file;url=$task.url;captured_at=[DateTimeOffset]::UtcNow.ToString('O')} | ConvertTo-Json -Compress
    $archive.Save($task.file,$bytes,$meta)
    $response.Dispose()
    [pscustomobject]@{file=$task.file;ok=$true;bytes=$bytes.Length};return
   }catch{$last=$_.Exception.Message;if($status -eq 404){break}}
  }
  [pscustomobject]@{file=$task.file;ok=$false;error=$last;status=$status}
 } -ThrottleLimit $Concurrency
} finally {$writer.Dispose();$http.Dispose()}
$results | ConvertTo-Json -Depth 4 | Set-Content (Join-Path $Root "$Prefix-results.json")
$results | Group-Object ok | Select-Object Name,Count
Write-Output "Elapsed seconds $($timer.Elapsed.TotalSeconds)"
