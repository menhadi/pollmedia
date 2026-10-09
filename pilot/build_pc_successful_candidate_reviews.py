"""Package four declared winners without changing unknown vote metrics."""
import copy
from io import BytesIO
import json
from pathlib import Path
import re
import tempfile
import zipfile
import fitz
from preserve_archive_json import package
from build_by_election_2018_cached_reviews import digest
from build_pc_1951_pepsu_three_summary_results import guarded_import_script

ROOT = Path(__file__).resolve().parents[1]
NAME = 'pollmedia-pc-successful-candidate-reviews-20261009'
SPECS = [
 ('02f72a2ef53a457f8e97e539', 'pollmedia-pc-1962-remaining-results-turnout-20261003', 'e31cfb2009ab1988edf51ac7fc668ef6a18b6a998680084ffd9fa79d4ae9beee', '3fe454045d11ad36de588f909a447e0afb11a20571b52d67f58f82e75a5d6f69', [224,307,360]),
 ('f536c924d1bedb6f02754d1d', 'pollmedia-pc-1977-sikkim-uncontested-result-20261005', '8f35a65b57427b17caded2918ba74e3c7f09c80f26cd85205dac95d1d3eba7de', '8aad2c95beb166b1d5db42a85f9891ba80e420d63597b93c4ac030615387b6a2', [43]),
]


def revised_files(root=ROOT):
    fixture = json.loads((root/'application/database/fixtures/official-successful-candidates.json').read_text())
    result = []
    for eid, name, outer_sha, prior_sha, codes in SPECS:
        path = root/'exports'/(name+'.zip')
        if digest(path.read_bytes()) != outer_sha:
            raise ValueError('Prior bundle differs')
        with zipfile.ZipFile(path) as outer, zipfile.ZipFile(BytesIO(outer.read('correction-'+eid+'.zip'))) as inner:
            old = inner.read('election-archive/'+eid+'/extraction.json')
        if digest(old) != prior_sha:
            raise ValueError('Prior extraction differs')
        data = json.loads(old)
        samples = []
        for code in codes:
            record, = [r for r in data['records'] if r['code'] == code]
            source = fixture[eid+':'+str(code)]
            candidate, = record['candidates']
            if (record['number_of_seats'] != 1 or record['state_name'] != source['state']
                    or record['constituency_name'] != source['name'] or record['official_pc_code'] != source['official_pc_code']
                    or candidate['candidate_name'] != source['winner'] or candidate['party_at_election'] != source['party']
                    or any(record[k] != 0 for k in ['votes_polled','valid_candidate_votes']) or candidate['votes'] != 0):
                raise ValueError('Original row identity or votes differ')
            pdf = root/'application/storage/app/private/election-archive'/eid/source['source_file']
            if pdf.is_symlink() or digest(pdf.read_bytes()) != source['source_sha256']:
                raise ValueError('Official source differs')
            with fitz.open(pdf) as document:
                text = document[source['pdf_page']-1].get_text()
            lines = [re.sub(r'\s+', ' ', line).strip() for line in text.splitlines()]
            heading = str(source['official_pc_code'])+'. '+source['name']
            if 'LIST OF SUCCESSFUL CANDIDATES' not in lines or heading not in lines or source['state'] not in lines:
                raise ValueError('Official list identity differs')
            index = lines.index(heading)
            if lines[index+1:index+3] != [source['winner'],source['party']]:
                raise ValueError('Official winner row differs')
            record['original_extraction_warning'] = record['error']
            record['error'] = ('The official successful-candidates list declares '+source['winner']+' ('+source['party']+'). '
                'Vote totals, turnout and margin are not established by this list. Original zero cells and warnings are preserved; '
                'no uncontested status is inferred.')
            record['source_warning_code'] = 'official_successful_candidate_only'
            record['official_successful_candidate'] = copy.deepcopy(source)
            record['official_source_url'] = source['source_url']
            samples.append(copy.deepcopy(record))
        result.append((eid,old,json.dumps(data,ensure_ascii=False,indent=2).encode(),samples))
    return result


def preflight():
    return r'''<?php
require '/home/pollmedia/app/application/vendor/autoload.php';
$app = require '/home/pollmedia/app/application/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$audit = json_decode(file_get_contents(__DIR__.'/AUDIT.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($audit as $edition) {
    $path = 'election-archive/'.$edition['edition'].'/extraction.json';
    if (is_file(storage_path('app/private/'.$path))) { throw new RuntimeException('Local shadow extraction'); }
    $row = \Illuminate\Support\Facades\DB::table('archive_json_files')->where('path_hash', hash('sha256', $path))->first();
    if (!$row || $row->path !== $path || !in_array($row->sha256, [$edition['previous_sha256'],$edition['new_sha256']], true) || hash('sha256',$row->body) !== $row->sha256) { throw new RuntimeException('Live predecessor conflict'); }
    foreach ($edition['samples'] as $record) {
        $result = app(\App\Services\HistoricalElectionAnalytics::class)->singleSeatResult($record,$edition['edition']);
        if (!($result['winner_only'] ?? false) || $result['margin'] !== null || $result['winner'] !== $record['official_successful_candidate']['winner']) { throw new RuntimeException('Pull tested winner-only code and fixture first'); }
    }
}
echo "PASS: live predecessors and winner-only capability verified.\n";
'''


def build(root=ROOT):
    output = root/'exports'/(NAME+'.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    revised = revised_files(root)
    content = {'PREFLIGHT.php': preflight().encode()}
    audit = []
    with tempfile.TemporaryDirectory(dir=root/'exports') as temporary:
        stage = Path(temporary)/'stage'
        for eid, old, new, samples in revised:
            prior = digest(old)
            snapshot = 'election-archive/'+eid+'/extraction-'+prior+'.json'
            revision = 'election-archive/'+eid+'/extraction.json'
            for kind, relative, body in [('snapshot',snapshot,old),('correction',revision,new)]:
                target = stage/relative;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(body)
                dest = Path(temporary)/(kind+'-'+eid+'.zip')
                package(stage,dest,'election-archive',0,1,[relative],prior if kind=='correction' else None,snapshot if kind=='correction' else None)
                content[dest.name] = dest.read_bytes()
            audit.append({'edition':eid,'previous_sha256':prior,'new_sha256':digest(new),'samples':samples})
        content['AUDIT.json'] = json.dumps(audit,indent=2).encode()
        content['ARCHIVES'] = ''.join(eid+'\n' for eid,_,_,_ in revised).encode()
        script = guarded_import_script().replace('10485760','10551296')
        marker = 'check_disk\nwhile IFS='
        if script.count(marker) != 1:
            raise ValueError('Import template differs')
        script = script.replace(marker,'check_disk\nphp8.4 PREFLIGHT.php\nwhile IFS=')
        script = script.replace('for file in correction-*.zip; do','php8.4 PREFLIGHT.php\nfor file in correction-*.zip; do')
        content['IMPORT.sh'] = script.encode()
        with zipfile.ZipFile(output,'x',compression=zipfile.ZIP_STORED) as z:
            for name,body in content.items():z.writestr(name,body)
            z.writestr('SHA256SUMS',''.join(digest(body)+'  '+name+'\n' for name,body in content.items()))
    output.with_suffix('.sha256').write_text(digest(output.read_bytes())+'  '+output.name+'\n')
    return {'bundle':str(output),'sha256':digest(output.read_bytes()),'editions':[{k:v for k,v in a.items() if k!='samples'} for a in audit]}


if __name__ == '__main__':
    print(json.dumps(build()))
