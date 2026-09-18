"""Preserve OCR text and word coordinates from scanned official polling-source pages."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import fitz
import cv2
import numpy as np
from PIL import Image
import pytesseract


def words_from_data(data):
    words = []
    for i, value in enumerate(data['text']):
        if not value.strip():
            continue
        words.append({'text':value, 'confidence':float(data['conf'][i]),
                      'left':int(data['left'][i]), 'top':int(data['top'][i]),
                      'width':int(data['width'][i]), 'height':int(data['height'][i]),
                      'block':int(data['block_num'][i]), 'paragraph':int(data['par_num'][i]), 'line':int(data['line_num'][i])})
    return words


def run(root, args):
    os.environ['OMP_THREAD_LIMIT'] = '1'
    executable = shutil.which('tesseract') or 'C:/Program Files/Tesseract-OCR/tesseract.exe'
    pytesseract.pytesseract.tesseract_cmd = executable
    if not re.fullmatch(r'[a-z_]+(?:\+[a-z_]+)*',args.language):
        raise ValueError('Invalid OCR language list')
    model_hashes = {}
    for language in args.language.split('+')+['osd']:
        path = args.tessdata/(language+'.traineddata')
        model_hashes[language] = hashlib.sha256(path.read_bytes()).hexdigest()
    engine = str(pytesseract.get_tesseract_version())
    profile = hashlib.sha256(json.dumps({'models':model_hashes,'engine':engine,'dpi':200,'adapter':'ocr-grid-v2','rotation':args.rotation},sort_keys=True).encode()).hexdigest()[:16]
    os.environ['TESSDATA_PREFIX'] = str(args.tessdata.resolve())
    config = ''
    completed = 0
    for path in root.glob('*/manifest.json'):
        manifest = json.loads(path.read_text(encoding='utf-8'))
        if args.state and manifest['state'] not in args.state:
            continue
        seen = set()
        for source in manifest['documents']:
            digest = source.get('sha256')
            if not source.get('file','').endswith('.pdf') or digest in seen:
                continue
            seen.add(digest)
            extracted = path.parent/(digest+'-tables')/'index.json'
            if not extracted.exists():
                continue
            candidates = [p for p in json.loads(extracted.read_text(encoding='utf-8'))['pages'] if any('Scanned or empty' in note for note in p['notes'])]
            if not candidates:
                continue
            original = path.parent/source['file']
            if hashlib.sha256(original.read_bytes()).hexdigest() != digest:
                raise ValueError('Original source checksum changed')
            destination = path.parent/(digest+'-ocr')
            destination.mkdir(exist_ok=True)
            index_path = destination/'index.json'
            index = json.loads(index_path.read_text(encoding='utf-8')) if index_path.exists() else {'pages':[]}
            pages = {p['page']:p for p in index['pages']}
            with fitz.open(original) as document:
                for page in candidates:
                    if page['page'] in pages:
                        saved = pages[page['page']]
                        if saved.get('profile') == profile and hashlib.sha256((destination/saved['file']).read_bytes()).hexdigest() == saved['sha256']:
                            continue
                    image = document[page['page']-1].get_pixmap(dpi=200,colorspace=fitz.csGRAY)
                    bitmap = Image.frombytes('L',[image.width,image.height],image.samples)
                    notes = ['OCR text is unverified. Word confidence is an engine estimate; no candidate, winner or vote total is inferred from this text.']
                    rotation = args.rotation or 0
                    try:
                        if args.rotation is None:
                            orientation = pytesseract.image_to_osd(bitmap,config=config,output_type=pytesseract.Output.DICT,timeout=30)
                            if orientation['orientation_conf'] >= 5:
                                rotation = int(orientation['rotate'])
                            else:
                                notes.append('Page orientation requires visual checking.')
                    except (RuntimeError,pytesseract.TesseractError):
                        notes.append('Automatic orientation could not be determined; original orientation was retained.')
                    bitmap = bitmap.rotate(-rotation,expand=True)
                    pixels = np.array(bitmap)
                    binary = cv2.threshold(pixels,0,255,cv2.THRESH_BINARY_INV|cv2.THRESH_OTSU)[1]
                    horizontal = cv2.morphologyEx(binary,cv2.MORPH_OPEN,cv2.getStructuringElement(cv2.MORPH_RECT,(max(40,bitmap.width//40),1)))
                    vertical = cv2.morphologyEx(binary,cv2.MORPH_OPEN,cv2.getStructuringElement(cv2.MORPH_RECT,(1,max(40,bitmap.height//40))))
                    pixels[cv2.bitwise_or(horizontal,vertical)>0] = 255
                    data = pytesseract.image_to_data(Image.fromarray(pixels),lang=args.language,config=config+' --psm 11',output_type=pytesseract.Output.DICT,timeout=120)
                    words = words_from_data(data)
                    low_confidence = sum(word['confidence'] < 60 for word in words)
                    quality = 'needs_visual_review' if not words or low_confidence > len(words)/3 else 'unverified_ocr'
                    if quality == 'needs_visual_review':
                        notes.append('OCR could not reliably read much of this page. A clearer official copy or manual transcription is required; do not use this text as election results.')
                    lines = {}
                    for word in words:
                        key = (word['block'],word['paragraph'],word['line'])
                        lines.setdefault(key,[]).append(word['text'])
                    result = {'source_sha256':digest,'source_url':source['url'],'page':page['page'],
                              'engine':engine,'model_sha256':model_hashes,'language':args.language,'dpi':200,
                              'rotation_clockwise':rotation,'image_width':bitmap.width,'image_height':bitmap.height,'preprocessing':'Long grid lines removed from OCR image only; original PDF preserved.',
                              'text':'\n'.join(' '.join(line) for line in lines.values()),'words':words,'notes':notes,'quality':quality}
                    body = json.dumps(result,ensure_ascii=False).encode('utf-8')
                    content_hash = hashlib.sha256(body).hexdigest()
                    filename = str(page['page'])+'-'+content_hash[:16]+'.json'
                    (destination/filename).write_bytes(body)
                    pages[page['page']] = {'page':page['page'],'file':filename,'sha256':content_hash,'profile':profile,
                                           'words':len(words),'low_confidence_words':low_confidence,'quality':quality}
                    index.update(pages=list(pages.values()),source_sha256=digest,source_url=source['url'])
                    temporary = index_path.with_suffix('.tmp')
                    temporary.write_text(json.dumps(index,ensure_ascii=False,indent=2),encoding='utf-8')
                    temporary.replace(index_path)
                    completed += 1
                    print(manifest['state']+' '+digest[:12]+' page '+str(page['page'])+': '+str(len(words))+' OCR words',flush=True)
                    if args.limit and completed >= args.limit:
                        return


if __name__ == '__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--state',action='append');parser.add_argument('--limit',type=int,default=0)
    parser.add_argument('--language',required=True);parser.add_argument('--tessdata',type=Path,required=True);parser.add_argument('--rotation',type=int,choices=[0,90,180,270]);args=parser.parse_args()
    run(Path(__file__).resolve().parents[1]/'application/storage/app/private/polling-station-sources',args)
