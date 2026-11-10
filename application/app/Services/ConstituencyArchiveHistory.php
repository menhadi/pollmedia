<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConstituencyArchiveHistory
{
    /** General-category annotations are metadata; preserve the original source labels in results. */
    public static function generalCategoryNames(string $name): array
    {
        $base = preg_replace('/\s*\(gen\)$/i', '', mb_strtolower(trim($name)));

        return [$base, $base.' (gen)', $base.'(gen)'];
    }

    /**
     * Exact official spelling variation, not fuzzy matching or a boundary equivalence claim.
     * 2022 district notice: https://imphaleast.nic.in/document/notice-of-election-in-respect-of-4-kshetrigao-ac/
     * CEO result sheet: https://ceomanipur.nic.in/ResultSheets/StateLegislativeAssembly/2022/04.pdf
     * Additional alias records must also have AC code 4; original-name matches remain unchanged.
     */
    public static function manipurAssemblyAliasNames(string $kind, ?string $state, string $name): array
    {
        if ($kind !== 'ac' || mb_strtolower(trim($state ?? '')) !== 'manipur') {
            return [];
        }
        $base = self::generalCategoryNames($name)[0];
        $alias = match ($base) {
            'kshetrigao' => 'khetrigao',
            'khetrigao' => 'kshetrigao',
            default => null,
        };

        return $alias === null ? [] : self::generalCategoryNames($alias);
    }

    /**
     * Explicit historical spelling links; raw labels and year-specific boundaries remain unchanged.
     * Naoriya is corroborated by official summaries (PDF p31) and candidate continuity:
     * https://old.eci.gov.in/files/file/3704-manipur-1974/
     * https://old.eci.gov.in/files/file/3705-manipur-1980/
     * Nambol/Nanbol is corroborated by candidate continuity in those reports and:
     * https://old.eci.gov.in/files/file/3703-manipur-1972/
     * Bishenpur directly links to Bishnupur in the same official 2017 election:
     * https://ceomanipur.nic.in/Affidavits/AssemblyElection/2017/ACList.html
     * https://ceomanipur.nic.in/Affidavits/AssemblyElection/2017/SE/26/AC26.htm
     * Khangabok in the CEO 2022 directory links directly to a Khangabo Form 20:
     * https://ceomanipur.nic.in/ResultSheets/StateLegislativeAssembly/2022/results
     * https://ceomanipur.nic.in/ResultSheets/StateLegislativeAssembly/2022/35.pdf
     * Khangabox is printed in the 1972 report above; candidate continuity links 1974.
     * Sugnoo has the same two candidates/parties as ECI 2017 Sugnu:
     * https://ceomanipur.nic.in/Affidavits/AssemblyElection/2017/SE/39/AC39.htm
     * The distinct 1967 Hiyanglam Sugnoo compound seat is not an alias.
     * Tipalmukh (ST) is printed in the 1974 report above (PDF p79); recurring
     * candidate Hmangkhum Joute corroborates Tipaimukh (ST) in 1980 (PDF p81).
     * Its public directory route and 2022 report omit ST; only this explicit seat
     * bridge accepts those labels, retaining all raw reservation annotations.
     * Hanglep (ST), 1974 PDF p79, is corroborated by winner Holkhomang in
     * Henglep (ST), 1972 PDF p80 and 1980 PDF p81 (official reports above).
     * Keishamthong in the CEO 2022 directory links directly to Keisamthong:
     * https://ceomanipur.nic.in/ResultSheets/StateLegislativeAssembly/2022/12.pdf
     * Historical identity inference is not a delimitation crosswalk.
     * Tripura's 2023 official map says Chhawmanu (ST), while CEO observer lists say Chawmanu:
     * https://ceotripura.nic.in/sites/default/files/2024-02/Press20for20Election20Meghalaya20Nagaland20Tripura.pdf
     * https://ceotripura.nic.in/sites/default/files/2023-10/TR.GEN_.OBS%2520-21.01.2023%2013.pdf
     * The 2018 Chawamanu workbook omits ST, corroborated by the same edition's
     * constituency summary PDF (49, Chawamanu ST) and candidate continuity in 2013.
     * https://old.eci.gov.in/files/file/3472-tripura-general-legislative-election-2018/
     * The same 2023 official map labels 53 Kailasahar; the 2023 ECI workbook
     * labels 53 Kailashahar. The link preserves all historical source spellings:
     * https://old.eci.gov.in/files/file/14875-tripura-general-legislative-election-2023/
     * That map also labels 33 Kakraban-Salgarah (SC), while the 2023 workbook
     * uses Kakraban-Shalgara (SC). The 2018 summary PDF p33 corroborates SC
     * omitted from its workbook; 2013 uses Kakraban-Salgarh (SC).
     * Only these combined-seat editions are linked, never earlier Kakraban/Salgarh seats.
     * The 2023 map likewise uses Kalyanpur-Pramodenagar, while its workbook
     * uses Kalyanpur-Pramodnagar. Only 2013/2018/2023 combined-seat records
     * are linked; earlier Kalyanpur and Pramodenagar remain separate.
     * Karamchara (2023 workbook) is Karamchhara (ST) on that election's map;
     * 2013 Karmachhara and 2018 Karmachara are corroborated by Diba Chandra
     * Hrangkhawl's candidate continuity. The 2018 summary PDF p48 retains ST.
     * Only those three editions are linked; earlier Kulai (ST) is not an alias.
     *
     * @return list<array{names: array, code: int, edition?: string, after?: int}>
     */
    public static function historyAliasRules(string $kind, ?string $state, string $name): array
    {
        // Jammu & Kashmir reports spell AC47 Bhaderwah as Bhadarwah in 1977-1987,
        // and the 2024 edition lists the same seat as Bhadarwah under the new code 51.
        if ($kind === 'ac' && in_array(mb_strtolower(trim($state ?? '')), ['jammu & kashmir', 'jammu and kashmir'], true)
            && in_array(self::generalCategoryNames($name)[0], ['bhaderwah', 'bhaderwah (sc)'], true)) {
            return [
                ['names' => ['bhadarwah (sc)'], 'code' => 47, 'edition' => '80f75fa4725b7bd73f593912'],
                ['names' => ['bhadarwah (sc)'], 'code' => 47, 'edition' => '2f28536c3658989c7519ffc4'],
                ['names' => ['bhadarwah (sc)'], 'code' => 47, 'edition' => '0cdaa92b57575eaccc2d59c5'],
                ['names' => ['bhadarwah'], 'code' => 51, 'edition' => '1e7b04dde5cfe815dbb49020'],
            ];
        }
        // ECI schedules (2014 p59, 2024 p112) name AC1 Yoksam-Tashiding BL;
        // the 2024 result uses Yuksom. The 2019 schedule p101 confirms that exact-year BL label too.
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'sikkim'
            && mb_strtolower(trim($name)) === 'yuksom-tashiding (bl)') {
            return [
                ['names' => ['yoksam-tashiding'], 'code' => 1, 'edition' => 'cdaa9ffba759d8ab2a2173cf'],
                ['names' => ['yoksam-tashiding (bl)'], 'code' => 1, 'edition' => 'a3d4a05dfbdc76e283649d0b'],
            ];
        }
        // ECI's 2014 schedule names AC7 Soreng-Chakung; that year's results
        // use Soreong-Chakung. Earlier editions require their own identity proof.
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'sikkim'
            && self::generalCategoryNames($name)[0] === 'soreng-chakung') {
            return [
                ['names' => ['soreong-chakung'], 'code' => 7, 'edition' => 'cdaa9ffba759d8ab2a2173cf'],
            ];
        }
        // The 2004 report labels AC32 Sangha in its highlights (PDF p6)
        // and Sangha (Sangha) in its summary/detail (pp43/47).
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'sikkim'
            && mb_strtolower(trim($name)) === 'sangha') {
            return [['names' => ['sangha (sangha)'], 'code' => 32, 'edition' => '9013d463bb4e332a159e41a1']];
        }
        // ECI's 5 March 2014 schedule, Statement G (PDF p59), lists these
        // same-numbered seats as BL. The 2014 result report omits that suffix.
        // Keep the bare names confined to that edition; do not rewrite categories.
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'sikkim') {
            $seat = match (mb_strtolower(trim($name))) {
                'barfung (bl)' => ['barfung', 9],
                'daramdin (bl)' => ['daramdin', 6],
                'djongu (bl)' => ['djongu', 30],
                'gangtok (bl)' => ['gangtok', 27],
                'gnathang-machong (bl)' => ['gnathang-machong', 21],
                'kabi lungchuk (bl)' => ['kabi lungchuk', 29],
                'lachen mangan (bl)' => ['lachen mangan', 31],
                'martam-rumtek (bl)' => ['martam-rumtek', 24],
                'rinchenpong (bl)' => ['rinchenpong', 5],
                'shyari (bl)' => ['shyari', 23],
                'tumen-lingi (bl)' => ['tumen-lingi', 16],
                default => null,
            };
            if ($seat !== null) {
                return [['names' => [$seat[0]], 'code' => $seat[1], 'edition' => 'cdaa9ffba759d8ab2a2173cf']];
            }
        }
        // The official 2023 map (PDF p47) labels 8 Town Bordowali, while
        // its workbook uses Town Bardowali. Earlier Agartala Town seats are distinct.
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'tripura'
            && in_array(self::generalCategoryNames($name)[0], ['town bardowali', 'town bordowali'], true)) {
            return [
                ['names' => self::generalCategoryNames('town bordowali'), 'code' => 8, 'edition' => 'f3146efb9111d25969c412f0'],
                ['names' => self::generalCategoryNames('town bordowali'), 'code' => 8, 'edition' => 'b0beb6c7b4e5f328cc4a5e39'],
                ['names' => self::generalCategoryNames('town bordowali'), 'code' => 8, 'edition' => 'bf27a1304f1e81e15b143c29'],
                ['names' => self::generalCategoryNames('town bordowali'), 'code' => 8, 'edition' => '8e7c85215854fcd5d5daab04'],
                ['names' => self::generalCategoryNames('town bordowali'), 'code' => 8, 'edition' => '2ac3d758410f3c59fe94dbdc'],
                ['names' => self::generalCategoryNames('town bordowali'), 'code' => 8, 'edition' => 'e956df9b147477572e6a99f1'],
                ['names' => self::generalCategoryNames('town bordowali'), 'code' => 8, 'edition' => 'c2b9ef2bc73bbcc70a271a58'],
                ['names' => self::generalCategoryNames('town bordowali'), 'code' => 8, 'edition' => 'ae51f7f9665bc5aca6facf61'],
                ['names' => self::generalCategoryNames('town bordowali'), 'code' => 8, 'edition' => 'd070812d58833a38f814391b'],
                ['names' => self::generalCategoryNames('town bardowali'), 'code' => 8, 'edition' => 'd74eaa9eba74f0984b16c588'],
            ];
        }
        // The official 2023 map (PDF p47) labels 31 Radhakishorepur, while
        // its workbook uses Radhakishorpur. Preserve edition-specific seat codes.
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'tripura'
            && in_array(self::generalCategoryNames($name)[0], ['radhakishorpur', 'radhakishorepur'], true)) {
            return [
                ['names' => self::generalCategoryNames('radhakishorepur'), 'code' => 14, 'edition' => '9cedf416aa4afcee8948ef91'],
                ['names' => self::generalCategoryNames('radhakishorepur'), 'code' => 27, 'edition' => 'a09382fa323d9af5f7af7d59'],
                ['names' => self::generalCategoryNames('radhakishorepur'), 'code' => 32, 'edition' => 'f3146efb9111d25969c412f0'],
                ['names' => self::generalCategoryNames('radhakishorepur'), 'code' => 32, 'edition' => 'b0beb6c7b4e5f328cc4a5e39'],
                ['names' => self::generalCategoryNames('radhakishorepur'), 'code' => 32, 'edition' => 'bf27a1304f1e81e15b143c29'],
                ['names' => self::generalCategoryNames('radhakishorepur'), 'code' => 32, 'edition' => '8e7c85215854fcd5d5daab04'],
                ['names' => self::generalCategoryNames('radhakishorepur'), 'code' => 32, 'edition' => '2ac3d758410f3c59fe94dbdc'],
                ['names' => self::generalCategoryNames('radhakishorepur'), 'code' => 32, 'edition' => 'e956df9b147477572e6a99f1'],
                ['names' => self::generalCategoryNames('radhakishorepur'), 'code' => 32, 'edition' => 'c2b9ef2bc73bbcc70a271a58'],
                ['names' => self::generalCategoryNames('radhakishorepur'), 'code' => 31, 'edition' => 'ae51f7f9665bc5aca6facf61'],
                ['names' => self::generalCategoryNames('radhakishorepur'), 'code' => 31, 'edition' => 'd070812d58833a38f814391b'],
                ['names' => self::generalCategoryNames('radhakishorpur'), 'code' => 31, 'edition' => 'd74eaa9eba74f0984b16c588'],
            ];
        }
        // The official 2023 map (PDF p47) labels 59 Pencharthal (ST), while
        // its workbook uses Pecharthal (ST). The 2018 summary PDF p59 retains ST.
        // Link only inspected editions, preserving historical codes and raw labels.
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'tripura'
            && in_array(mb_strtolower(trim($name)), ['pecharthal (st)', 'pencharthal (st)', 'pencharthal  (st)', 'pencharthal'], true)) {
            return [
                ['names' => ['pencharthal (st)'], 'code' => 58, 'edition' => 'f3146efb9111d25969c412f0'],
                ['names' => ['pencharthal (st)'], 'code' => 58, 'edition' => 'b0beb6c7b4e5f328cc4a5e39'],
                ['names' => ['pencharthal (st)'], 'code' => 58, 'edition' => 'bf27a1304f1e81e15b143c29'],
                ['names' => ['pencharthal (st)'], 'code' => 58, 'edition' => '8e7c85215854fcd5d5daab04'],
                ['names' => ['pencharthal (st)'], 'code' => 58, 'edition' => '2ac3d758410f3c59fe94dbdc'],
                ['names' => ['pencharthal (st)'], 'code' => 58, 'edition' => 'e956df9b147477572e6a99f1'],
                ['names' => ['pencharthal  (st)', 'pencharthal (st)'], 'code' => 58, 'edition' => 'c2b9ef2bc73bbcc70a271a58'],
                ['names' => ['pencharthal  (st)', 'pencharthal (st)'], 'code' => 59, 'edition' => 'ae51f7f9665bc5aca6facf61'],
                ['names' => ['pencharthal'], 'code' => 59, 'edition' => 'd070812d58833a38f814391b'],
                ['names' => ['pecharthal (st)'], 'code' => 59, 'edition' => 'd74eaa9eba74f0984b16c588'],
            ];
        }
        // The 2023 official map calls AC 50 Pabiachhara (SC), while its workbook
        // calls it Pabiachara (SC). The 2018 summary PDF p50 retains SC omitted
        // by that workbook. Do not join the 1972 Pabiachherra (ST) record.
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'tripura'
            && in_array(mb_strtolower(trim($name)), ['pabiachara (sc)', 'pabiachhara (sc)', 'pabiachhara  (sc)', 'pabiachhara'], true)) {
            return [
                ['names' => ['pabiachara (sc)', 'pabiachhara (sc)', 'pabiachhara  (sc)'], 'code' => 50, 'after' => 1972],
                ['names' => ['pabiachhara'], 'code' => 50, 'edition' => 'd070812d58833a38f814391b'],
            ];
        }
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'tripura'
            && in_array(mb_strtolower(trim($name)), ['karamchara (st)', 'karmachhara (st)', 'karmachhara  (st)', 'karmachara', 'karamchhara (st)'], true)) {
            return [
                ['names' => ['karamchara (st)'], 'code' => 48, 'edition' => 'd74eaa9eba74f0984b16c588'],
                ['names' => ['karmachhara (st)', 'karmachhara  (st)'], 'code' => 48, 'edition' => 'ae51f7f9665bc5aca6facf61'],
                ['names' => ['karmachara'], 'code' => 48, 'edition' => 'd070812d58833a38f814391b'],
            ];
        }
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'tripura'
            && in_array(self::generalCategoryNames($name)[0], ['kalyanpur-pramodnagar', 'kalyanpur-pramodenagar'], true)) {
            return [
                ['names' => self::generalCategoryNames('kalyanpur-pramodnagar'), 'code' => 27, 'edition' => 'd74eaa9eba74f0984b16c588'],
                ['names' => self::generalCategoryNames('kalyanpur-pramodenagar'), 'code' => 27, 'edition' => 'ae51f7f9665bc5aca6facf61'],
                ['names' => self::generalCategoryNames('kalyanpur-pramodenagar'), 'code' => 27, 'edition' => 'd070812d58833a38f814391b'],
            ];
        }
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'tripura'
            && in_array(mb_strtolower(trim($name)), ['kakraban-shalgara (sc)', 'kakraban-salgarh (sc)', 'kakraban-salgarh  (sc)', 'kakraban-salgarah (sc)', 'kakraban-salgarah'], true)) {
            return [
                ['names' => ['kakraban-shalgara (sc)'], 'code' => 33, 'edition' => 'd74eaa9eba74f0984b16c588'],
                ['names' => ['kakraban-salgarh (sc)', 'kakraban-salgarh  (sc)'], 'code' => 33, 'edition' => 'ae51f7f9665bc5aca6facf61'],
                ['names' => ['kakraban-salgarah'], 'code' => 33, 'edition' => 'd070812d58833a38f814391b'],
            ];
        }
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'tripura'
            && in_array(self::generalCategoryNames($name)[0], ['kailashahar', 'kailasahar'], true)) {
            return [
                ['names' => array_merge(self::generalCategoryNames('kailasahar'), self::generalCategoryNames('kailashahar')), 'code' => 53, 'after' => 1972],
                ['names' => self::generalCategoryNames('kailashahar'), 'code' => 26, 'edition' => '9cedf416aa4afcee8948ef91'],
                ['names' => self::generalCategoryNames('kailashahar'), 'code' => 52, 'edition' => 'a09382fa323d9af5f7af7d59'],
            ];
        }
        if ($kind === 'ac' && mb_strtolower(trim($state ?? '')) === 'tripura'
            && in_array(mb_strtolower(trim($name)), ['chawmanu (st)', 'chhawmanu (st)', 'chhawmanu  (st)', 'chawamanu (st)', 'chawamanu  (st)', 'chawamanu'], true)) {
            return [
                ['names' => ['chawmanu (st)', 'chhawmanu (st)', 'chhawmanu  (st)', 'chawamanu (st)', 'chawamanu  (st)'], 'code' => 49, 'after' => 1972],
                ['names' => ['chhawmanu (st)'], 'code' => 48, 'edition' => 'a09382fa323d9af5f7af7d59'],
                ['names' => ['chawamanu'], 'code' => 49, 'edition' => 'd070812d58833a38f814391b'],
            ];
        }
        $names = self::manipurAssemblyAliasNames($kind, $state, $name);
        if ($names !== []) {
            return [['names' => $names, 'code' => 4]];
        }
        if ($kind !== 'ac' || mb_strtolower(trim($state ?? '')) !== 'manipur') {
            return [];
        }

        return match (self::generalCategoryNames($name)[0]) {
            'keisamthong' => [['names' => self::generalCategoryNames('keishamthong'), 'code' => 12]],
            'keishamthong' => [
                ['names' => self::generalCategoryNames('keisamthong'), 'code' => 12],
                ['names' => self::generalCategoryNames('keisamthong'), 'code' => 9, 'edition' => 'c2f796a4415124c983df09b7'],
                ['names' => self::generalCategoryNames('keisamthong'), 'code' => 17, 'edition' => '496d7edbfe44e6b6cf4b312b'],
            ],
            'henglep', 'henglep (st)', 'henglep  (st)', 'hanglep', 'hanglep (st)' => [
                ['names' => ['hanglep (st)', 'hanglep'], 'code' => 57, 'edition' => '48bac24675875f468956cf9e'],
                ['names' => ['henglep', 'henglep (st)', 'henglep  (st)'], 'code' => 57, 'after' => 1974],
                ['names' => ['henglep', 'henglep (st)'], 'code' => 58, 'edition' => '496d7edbfe44e6b6cf4b312b'],
            ],
            'tipaimukh', 'tipaimukh (st)', 'tipaimukh  (st)', 'tipalmukh', 'tipalmukh (st)' => [
                ['names' => ['tipalmukh (st)', 'tipalmukh'], 'code' => 55, 'edition' => '48bac24675875f468956cf9e'],
                ['names' => ['tipaimukh', 'tipaimukh (st)', 'tipaimukh  (st)'], 'code' => 55, 'after' => 1974],
                ['names' => ['tipaimukh', 'tipaimukh (st)'], 'code' => 56, 'edition' => '496d7edbfe44e6b6cf4b312b'],
            ],
            'sugnoo' => [['names' => self::generalCategoryNames('sugnu'), 'code' => 39]],
            'sugnu' => [
                ['names' => self::generalCategoryNames('sugnoo'), 'code' => 39],
                ['names' => self::generalCategoryNames('sugnoo'), 'code' => 40, 'edition' => '496d7edbfe44e6b6cf4b312b'],
            ],
            'khangabok' => [
                ['names' => self::generalCategoryNames('khangabo'), 'code' => 35],
                ['names' => self::generalCategoryNames('khangabox'), 'code' => 35, 'edition' => '496d7edbfe44e6b6cf4b312b'],
            ],
            'khangabo', 'khangabox' => [
                ['names' => self::generalCategoryNames('khangabok'), 'code' => 35],
                ['names' => self::generalCategoryNames('khangabok'), 'code' => 18, 'edition' => 'c2f796a4415124c983df09b7'],
                ['names' => self::generalCategoryNames('khangabo'), 'code' => 35],
                ['names' => self::generalCategoryNames('khangabox'), 'code' => 35, 'edition' => '496d7edbfe44e6b6cf4b312b'],
            ],
            'bishenpur' => [['names' => self::generalCategoryNames('bishnupur'), 'code' => 26]],
            'bishnupur' => [['names' => self::generalCategoryNames('bishenpur'), 'code' => 26]],
            'nambol' => [['names' => self::generalCategoryNames('nanbol'), 'code' => 24, 'edition' => '48bac24675875f468956cf9e']],
            'nanbol' => [
                ['names' => self::generalCategoryNames('nambol'), 'code' => 24, 'after' => 1974],
                ['names' => self::generalCategoryNames('nambol'), 'code' => 25, 'edition' => '496d7edbfe44e6b6cf4b312b'],
            ],
            'naoriya pakhanglakpa' => [['names' => self::generalCategoryNames('naoriya pakanglakpa'), 'code' => 21, 'edition' => '48bac24675875f468956cf9e']],
            'naoriya pakanglakpa' => [['names' => self::generalCategoryNames('naoriya pakhanglakpa'), 'code' => 21, 'after' => 1974]],
            default => [],
        };
    }

    /** Read preserved imported editions omitted from the search index; never write or extract data. */
    public function missingEntries(string $kind, ?string $state, ?string $name, Collection $indexed, ?string $editionOnly = null): Collection
    {
        if (! Schema::hasTable('archive_json_files')) {
            return collect();
        }
        $files = DB::table('archive_json_files')->where('category', 'election-archive')->where('path', 'like', 'election-archive/%/extraction.json')
            ->when($editionOnly, fn ($query) => $query->where('path_hash', hash('sha256', 'election-archive/'.$editionOnly.'/extraction.json')))->get(['path', 'sha256']);
        if ($files->isEmpty()) {
            return collect();
        }
        $catalogue = app(ElectionArchive::class);
        $editions = [];
        foreach ($catalogue->publicCatalogue()[$kind] as [$label, $url]) {
            $editions[substr(hash('sha256', $url), 0, 24)] = ['url' => $url, 'year' => (int) substr($label, 0, 4), 'label' => $label];
        }
        if ($kind === 'ac') {
            foreach ($catalogue->nationalAssemblyEntries() as $source) {
                $editions[substr(hash('sha256', $source['url']), 0, 24)] = ['url' => $source['url'], 'year' => (int) substr($source['label'], 0, 4), 'label' => $source['label'], 'state' => $source['state']];
            }
        }
        $known = $indexed->pluck('edition_id')->all();
        $knownRows = $indexed->map(fn ($row) => $row->edition_id.':'.$row->record_code)->all();
        $result = collect();
        foreach ($files as $file) {
            if (! preg_match('~^election-archive/([a-f0-9]{24})/extraction\.json$~', $file->path, $match)) {
                continue;
            }
            $id = $match[1];
            $edition = $editions[$id] ?? null;
            if ($edition && $state !== null && isset($edition['state']) && $this->stateName($edition['state']) !== $this->stateName($state)) {
                continue;
            }
            if (! $edition || ($name !== null && in_array($id, $known, true))) {
                continue;
            }
            $key = 'constituency-source-history-v30:'.hash('sha256', json_encode([$file->sha256, $kind, $state, $name]));
            $rows = Cache::remember($key, 900, function () use ($file, $kind, $state, $name, $id, $edition): array {
                $disk = app(ArchiveFiles::class);
                $body = $disk->get($file->path);
                abort_unless(hash_equals($file->sha256, hash('sha256', $body)), 409, 'Historical extraction checksum differs.');
                $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                abort_unless(($data['source_url'] ?? null) === $edition['url'] && ($data['kind'] ?? null) === $kind && ($data['year'] ?? null) === $edition['year'], 409, 'Historical extraction edition differs.');
                $manifest = json_decode($disk->get('election-archive/'.$id.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
                $hashes = collect($manifest['files'] ?? [])->pluck('sha256', 'file');
                abort_unless(($manifest['url'] ?? null) === $edition['url'] && preg_match('/^[a-f0-9]{64}$/', $data['source_sha256'] ?? '') && $hashes->get($data['source_file'] ?? null) === $data['source_sha256'], 409, 'Historical source manifest differs.');
                foreach ($data['additional_sources'] ?? [] as $source) {
                    abort_unless($hashes->get($source['file'] ?? null) === ($source['sha256'] ?? null), 409, 'Historical additional source differs.');
                }
                $matches = [];
                $wantedState = $state !== null ? $this->stateName($state) : null;
                $wantedSeat = $name !== null ? $this->seatName($name) : null;
                $aliases = $name !== null ? self::historyAliasRules($kind, $state, $name) : [];
                $stateNames = [];
                $stateCodes = [];
                foreach ($data['records'] ?? [] as $record) {
                    $recordName = $record['constituency_name'] ?? $record['name'] ?? '';
                    $code = $record['state_code'] ?? '';
                    $recordState = $record['state_name'] ?? ($edition['state'] ?? ($kind === 'ac' ? 'Uttar Pradesh' : ($edition['year'] >= 1977 ? ($stateCodes[$code] ??= ElectionPlaceIdentity::state($code)) : '')));
                    // Old state codes have different meanings. Use original state names, never today's code list.
                    $aliasMatches = collect($aliases)->contains(fn (array $rule): bool => (int) ($record['code'] ?? 0) === $rule['code']
                        && (! isset($rule['edition']) || $id === $rule['edition'])
                        && (! isset($rule['after']) || $edition['year'] > $rule['after'])
                        && in_array(mb_strtolower(trim($recordName)), $rule['names'], true));
                    if (($wantedState !== null && ($stateNames[$recordState] ??= $this->stateName($recordState)) !== $wantedState) || ($wantedSeat !== null && $this->seatName($recordName) !== $wantedSeat && ! $aliasMatches)) {
                        continue;
                    }
                    $matches[] = ['edition_id' => $id, 'record_code' => (int) $record['code'], 'kind' => $kind, 'year' => $edition['year'], 'edition_label' => $edition['label'], 'state_label' => $recordState, 'constituency_name' => $recordName, 'status' => $record['status'] ?? 'needs_review', 'has_warning' => ($record['status'] ?? '') !== 'validated', 'candidate_count' => count($record['candidates'] ?? []), 'extraction_sha256' => $file->sha256];
                }

                return $matches;
            });
            foreach ($rows as $row) {
                if (! in_array($id.':'.$row['record_code'], $knownRows, true)) {
                    $result->push((object) $row);
                }
            }
        }

        return $result;
    }

    private function stateName(string $value): string
    {
        return str_replace('&', 'and', mb_strtolower(ElectionPlaceIdentity::state($value)));
    }

    private function seatName(string $value): string
    {
        $value = self::generalCategoryNames($value)[0];
        $value = preg_replace('/\((?:sc|st)\)/i', '', mb_strtolower(trim($value)));
        $parts = explode('/', $value);

        return preg_replace('/[^\pL\pN]+/u', '', end($parts));
    }
}
