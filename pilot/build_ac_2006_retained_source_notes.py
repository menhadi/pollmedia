"""Document retained official-summary conflicts without changing source votes."""
import json
import re
from io import BytesIO
import zipfile
import fitz
import build_pc_successful_candidate_reviews as shared

NAME = 'pollmedia-ac-2006-retained-source-notes-20261009'


def revised_files(root=shared.ROOT):
    specs = json.loads((root/'pilot/ac_2006_retained_source_notes.json').read_text())
    revisions = []
    for spec in specs:
        eid = spec['edition']
        path = root/'exports'/(spec['prior_bundle']+'.zip')
        if shared.digest(path.read_bytes()) != spec['outer_sha']:
            raise ValueError('Predecessor package differs')
        with zipfile.ZipFile(path) as z, zipfile.ZipFile(BytesIO(z.read('correction-'+eid+'.zip'))) as q:
            old = q.read('election-archive/'+eid+'/extraction.json')
        if shared.digest(old) != spec['prior_sha']:
            raise ValueError('Predecessor extraction differs')
        data = json.loads(old)
        if data['kind'] != 'ac' or data['year'] != 2006 or data['source_url'] != spec['source_url']:
            raise ValueError('Edition identity differs')
        pdf = root/'application/storage/app/private/election-archive'/eid/spec['source_file']
        if pdf.is_symlink() or shared.digest(pdf.read_bytes()) != spec['source_sha']:
            raise ValueError('Official PDF differs')
        samples = []
        with fitz.open(pdf) as doc:
            for target in spec['targets']:
                r, = [r for r in data['records'] if r['code'] == target['code']]
                text = doc[target['page']-1].get_text(sort=True)
                match = re.search(r'\bMARGIN\s+(\d+)', text)
                if not match or int(match[1]) != target['printed_margin']:
                    raise ValueError('Printed margin differs')
                for name, party, votes in target['declared']:
                    if str(votes) not in text or party not in text:
                        raise ValueError('Official declaration cells differ')
                ranked = sorted(r['candidates'], key=lambda c:c['votes'], reverse=True)
                if (r['status'] != 'needs_review' or 'source_warning_code' in r
                        or ranked[0]['votes']-ranked[1]['votes'] != target['detail_margin']
                        or r['summary_page'] != target['page']):
                    raise ValueError('Retained candidate result differs')
                winner, runner = target['declared']
                note = (f'Official summary page {target["page"]} prints {winner[0]} ({winner[1]}) '
                    f'{winner[2]:,} votes and {runner[0]} ({runner[1]}) {runner[2]:,}, '
                    f'with margin {target["printed_margin"]:,}. ')
                if target['printed_margin'] != target['detail_margin']:
                    note += (f'The retained detailed candidate table gives {ranked[0]["candidate_name"]} '
                        f'({ranked[0]["party_at_election"]}) {ranked[0]["votes"]:,} votes and '
                        f'{ranked[1]["candidate_name"]} ({ranked[1]["party_at_election"]}) '
                        f'{ranked[1]["votes"]:,}, a difference of {target["detail_margin"]:,}. '
                        'These official sections disagree; displayed candidate-derived values remain under review. ')
                else:
                    note += 'Totals and margin agree; summary ADMK versus detailed AIADMK labels and runner-name spacing differ. '
                note += 'Original candidate rows, party labels and extraction warnings are preserved. See the official source file.'
                r['previous_review_note'] = r['error']
                r['error'] = note
                r['official_source_url'] = spec['source_url']
                r['summary_source_file'] = spec['source_file']
                r['summary_source_sha256'] = spec['source_sha']
                r['official_summary_discrepancy_review'] = target
                samples.append(r)
        revisions.append((eid,old,json.dumps(data,ensure_ascii=False,indent=2).encode(),samples))
    return revisions


def build():
    preflight = shared.preflight()
    start = preflight.index("        if (!($result['winner_only']")
    end = preflight.index('\n',start)
    preflight = preflight[:start]+"        if (($result['margin'] ?? null) !== $record['official_summary_discrepancy_review']['detail_margin'] || app(\\App\\Services\\HistoricalElectionAnalytics::class)->summarize([$record])['party_count'] !== 1) { throw new RuntimeException('Preserved candidate projection missing'); }"+preflight[end:]
    result = shared.build(name=NAME,revised_data=revised_files(),preflight_body=preflight)
    (shared.ROOT/'exports'/(NAME+'.sha256')).write_bytes((result['sha256']+'  '+NAME+'.zip\n').encode())
    return result


if __name__ == '__main__':
    print(json.dumps(build()))
