"""Bridge four byte-different, semantically identical live election versions."""
from io import BytesIO
import json
import zipfile
import build_pc_successful_candidate_reviews as shared

NAME = 'pollmedia-four-live-election-bridge-20261009'
LIVE_BUNDLE = 'pollmedia-election-corrections-20261003-resume-v1.zip'
SPECS = [
 ('3250a94d4b625ec2bea29016','pollmedia-ac-1989-tamil-nadu-declared-results-20261007','32c1c02a646c028bec37d94d09858bae6724f1f6b03170b1ca73956ef3f4b53f'),
 ('8393265a724e9a7a3fa5abf4','pollmedia-ac-2007-punjab-declared-results-20261008','7e9654c7caa02bfbc70688ac71fbf5e43c7b8ed2f09520acf3b9b57421629155'),
 ('3fdbf401aeb74266e09309ab','pollmedia-ac-2007-uttarakhand-declared-results-20261008','1f9fd04bd5a20684634ea4f0919015873fd77812731487ea1c2fc428f8b26b97'),
 ('174ec81b511a8fb1aeca553f','pollmedia-ac-2007-up-remaining-results-20261008','bdeeb6c61688ad59e57f906a011ecdfc608fea14c423a300dd2c7df391a8b435'),
]


def read_inner(root, bundle, eid, kind):
    path = root/'exports'/bundle
    if shared.digest(path.read_bytes()) != path.with_suffix('.sha256').read_text().split()[0]:
        raise ValueError('Bundle receipt conflict')
    with zipfile.ZipFile(path) as outer, zipfile.ZipFile(BytesIO(outer.read(kind+'-'+eid+'.zip'))) as inner:
        files = [n for n in inner.namelist() if n.startswith('election-archive/') and n.endswith('.json')]
        name, = files
        return inner.read(name)


def normalized(body):
    data = json.loads(body)
    codes = [r['code'] for r in data['records']]
    if len(codes) != len(set(codes)):
        raise ValueError('Duplicate code')
    data['records'] = sorted(data['records'], key=lambda r:r['code'])
    return data


def revised_files(root=shared.ROOT):
    result = []
    for eid, bundle, live_sha in SPECS:
        live = read_inner(root,LIVE_BUNDLE,eid,'correction')
        before = read_inner(root,bundle+'.zip',eid,'snapshot')
        after = read_inner(root,bundle+'.zip',eid,'correction')
        if shared.digest(live) != live_sha or normalized(live) != normalized(before):
            raise ValueError('Live contents differ beyond serialization/order')
        old = {r['code']:r for r in json.loads(before)['records']}
        samples = [r for r in json.loads(after)['records'] if r != old[r['code']]]
        for r in samples:
            if r['candidates'] != old[r['code']]['candidates']:
                raise ValueError('Candidate bytes would change')
        result.append((eid,live,after,samples))
    return result


def build():
    preflight = shared.preflight()
    start = preflight.index("        if (!($result['winner_only']")
    end = preflight.index('\n',start)
    preflight = preflight[:start]+"        if ($result === null) { throw new RuntimeException('Pull tested election result code first'); }"+preflight[end:]
    preflight = preflight.replace('winner-only capability','declared-result capability')
    result = shared.build(name=NAME,revised_data=revised_files(),preflight_body=preflight)
    path = shared.ROOT/'exports'/(NAME+'.sha256')
    path.write_bytes((result['sha256']+'  '+NAME+'.zip\n').encode('ascii'))
    return result


if __name__ == '__main__':
    print(json.dumps(build()))
