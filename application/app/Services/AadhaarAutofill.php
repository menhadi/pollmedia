<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

class AadhaarAutofill
{
    public function read(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $process = new Process(['tesseract', $path, 'stdout', '-l', 'eng+hin', '--psm', '11'], env: ['OMP_THREAD_LIMIT' => '1']);
        $process->setTimeout(25);
        try {
            $process->run();

            return $process->isSuccessful() ? $this->parse($process->getOutput()) : [];
        } catch (ExceptionInterface) {
            return [];
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function parse(string $text): array
    {
        $text = strtr(mb_substr($text, 0, 30000), array_combine(preg_split('//u', '०१२३४५६७८९', -1, PREG_SPLIT_NO_EMPTY), range(0, 9)));
        if (! preg_match('/aadhaar|uidai|आधार|government\s+of\s+india|भारत\s*सरकार/iu', $text)) {
            return [];
        }
        $text = preg_replace('/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/', '', $text);
        $fields = [];
        if (preg_match('/(?:DOB|date\s*of\s*birth|जन्म\s*तिथि)\s*[:\-]?\s*(\d{2})[\/.-](\d{2})[\/.-](\d{4})/iu', $text, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3]) && $m[3] >= 1900 && $m[3] <= date('Y')) {
            $fields['dob'] = $m[3].'-'.$m[2].'-'.$m[1];
        } elseif (preg_match('/(?:year\s*of\s*birth|जन्म\s*वर्ष|YOB)\s*[:\-]?\s*((?:19|20)\d{2})/iu', $text, $m)) {
            $fields['birth_year'] = $m[1];
        }
        if (preg_match('/\b([1-9]\d{5})\b/', $text, $m)) {
            $fields['pincode'] = $m[1];
        }
        $lines = preg_split('/\R/u', $text);
        foreach ($lines as $i => $line) {
            if (preg_match('/^(?:Name|नाम)\s*[:\-]\s*([\p{L}\p{M} .]{3,80})$/iu', trim($line), $m)) {
                $fields['name'] = trim($m[1]);
                break;
            }
            if (preg_match('/DOB|date\s*of\s*birth|जन्म\s*तिथि/iu', $line) && $i > 0) {
                $candidate = trim($lines[$i - 1]);
                if (preg_match('/^[\p{L}\p{M} .]{3,80}$/u', $candidate) && ! preg_match('/government|india|आधार|भारत|address|male|female|नाम|जन्म|father|husband/iu', $candidate)) {
                    $fields['name'] = $candidate;
                }
            }
        }
        $directory = app(MyPlaceDirectory::class);
        $states = [];
        foreach ($directory->states() as $state) {
            $hindi = app(HindiPlaceNames::class)->translated($state['name'], true);
            if ($this->mentions($text, $state['name']) || ($hindi && $this->mentions($text, $hindi))) {
                $states[] = $state['code'];
            }
        }
        if (count($states) === 1) {
            $fields['state'] = (string) $states[0];
            $districts = [];
            foreach ($directory->districts($fields['state']) as $district) {
                $hindi = app(HindiPlaceNames::class)->translated($district['name'], true);
                if ($this->mentions($text, $district['name']) || ($hindi && $this->mentions($text, $hindi))) {
                    $districts[] = $district['code'];
                }
            }
            if (count($districts) === 1) {
                $fields['district'] = (string) $districts[0];
            }
        }

        return $fields;
    }

    private function mentions(string $text, string $name): bool
    {
        return (bool) preg_match('/(?<![\p{L}\p{M}])'.preg_quote($name, '/').'(?![\p{L}\p{M}])/iu', $text);
    }
}
