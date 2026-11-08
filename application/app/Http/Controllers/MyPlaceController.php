<?php

namespace App\Http\Controllers;

use App\Services\ElectoralVillageMap;
use App\Services\HindiPlaceNames;
use App\Services\IssueLocalityResolver;
use App\Services\IssueLocationResolver;
use App\Services\MyPlaceDirectory;
use App\Services\PilibhitMyPlace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

class MyPlaceController extends Controller
{
    public function start(Request $request, MyPlaceDirectory $directory): View
    {
        $data = $request->validate(['state' => 'nullable|regex:/^[0-9]{1,3}$/', 'district' => 'nullable|regex:/^[0-9]{1,8}$/', 'village' => 'nullable|regex:/^[0-9]{1,8}$/', 'subdistrict' => ['nullable', 'regex:/^(?:[0-9]{1,8}|unknown)$/'], 'locality' => 'nullable|string|max:10000', 'lang' => 'nullable|in:en,hi']);
        $context = ! empty($data['state']) && ! empty($data['district']) ? $directory->context($data['state'], $data['district'], $data['village'] ?? null) : null;
        if ($context) {
            $context = $this->withLocality($request, $context);
        }
        if (! empty($context['village']['subdistrict_code'])) {
            $data['subdistrict'] = $context['village']['subdistrict_code'];
        }

        return view('my-place-start', ['context' => $context, 'initial' => $data, 'language' => app()->getLocale() === 'hi' ? 'hi' : 'en']);
    }

    public function summary(Request $request, MyPlaceDirectory $directory): View
    {
        $data = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'required|regex:/^[0-9]{1,8}$/', 'village' => 'nullable|regex:/^[0-9]{1,8}$/']);

        return view('my-place-summary', ['context' => $this->withLocality($request, $directory->context($data['state'], $data['district'], $data['village'] ?? null)), 'language' => app()->getLocale() === 'hi' ? 'hi' : 'en']);
    }

    public function options(Request $request, ElectoralVillageMap $map): JsonResponse
    {
        $response = app(LocatedIssueController::class)->options($request, $map);
        $options = $response->getData(true)['options'];
        if (app()->getLocale() === 'hi') {
            $names = app(HindiPlaceNames::class);
            foreach ($options as &$option) {
                $option['label'] = implode(' · ', array_map(fn ($name) => $names->translated($name, true) ?? $name, explode(' · ', $option['name'])));
            }
        }

        return response()->json(['options' => $options]);
    }

    public function locate(Request $request, IssueLocationResolver $resolver): JsonResponse
    {
        $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'accuracy' => 'required|numeric|min:0|max:100000']);
        $choices = [];
        $locations = $data['accuracy'] > 100
            ? [app(IssueLocalityResolver::class)->resolve((float) $data['latitude'], (float) $data['longitude'])]
            : $resolver->resolve((float) $data['latitude'], (float) $data['longitude']);
        foreach ($locations as $location) {
            if (empty($location['state']) || empty($location['district'])) {
                continue;
            }
            $village = $data['accuracy'] <= 100 ? ($location['source']['lgd'] ?? null) : null;
            $locality = ! $village && ! empty($location['source']['locality']);
            $choices[] = ['name' => $village || $locality ? $location['name'] : collect($location['scopes'])->firstWhere('kind', 'district')['name'], 'state' => $location['state'], 'district' => $location['district'], 'village' => $village,
                'locality' => $locality ? Crypt::encryptString(json_encode(['state' => $location['state'], 'district' => $location['district'], 'name' => $location['name'], 'scopes' => $location['scopes']])) : null];
        }

        return response()->json(['choices' => collect($choices)->unique(fn ($c) => $c['state'].':'.$c['district'].':'.$c['village'])->values(), 'accuracy' => $data['accuracy']])->header('Cache-Control', 'private, no-store');
    }

    private function withLocality(Request $request, array $context): array
    {
        $input = $request->validate(['locality' => 'nullable|string|max:10000']);
        if (empty($input['locality']) || $context['village']) {
            return $context;
        }
        try {
            $locality = json_decode(Crypt::decryptString($input['locality']), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, 'The detected locality could not be confirmed. Use my location again.');
        }
        abort_unless((string) $locality['state'] === (string) $context['state']['code'] && (string) $locality['district'] === (string) $context['district']['code'], 422);
        $context['locality'] = $locality;

        return $context;
    }

    public function index(Request $request, PilibhitMyPlace $places): View|RedirectResponse
    {
        $input = $request->validate(['village' => ['nullable', 'regex:/^[0-9]{1,8}$/'], 'subdistrict' => ['nullable', 'regex:/^[0-9]{1,8}$/'], 'lang' => 'nullable|in:en,hi']);
        $language = $input['lang'] ?? app()->getLocale();
        $language = $language === 'hi' ? 'hi' : 'en';
        $villages = $places->villages();
        $selected = isset($input['village']) ? collect($villages)->firstWhere('code', $input['village']) : null;
        abort_if(isset($input['village']) && ! $selected, 404);
        $subdistrict = $selected['subdistrict_code'] ?? ($input['subdistrict'] ?? '');
        abort_if($subdistrict !== '' && ! collect($villages)->contains('subdistrict_code', $subdistrict), 404);
        if ($request->query('view') !== 'details' && $request->query('topic', 'overview') === 'overview') {
            return redirect()->route('my-place.index', array_filter(['state' => '9', 'district' => '173', 'village' => $selected['code'] ?? null, 'subdistrict' => $subdistrict, 'lang' => $language]));
        }
        if ($language === 'hi') {
            $names = app(HindiPlaceNames::class);
            foreach ($villages as &$village) {
                $village['display_name'] = $names->translated($village['name'], true) ?? $village['name'];
                $village['display_subdistrict'] = $names->translated($village['subdistrict'], true) ?? $village['subdistrict'];
            }
            unset($village);
        }
        $title = $selected ? collect($villages)->firstWhere('code', $selected['code'])['display_name'] ?? $selected['name'] : ($language === 'hi' ? 'पीलीभीत' : 'Pilibhit');

        return view('my-place', ['language' => $language, 'villages' => $villages, 'selected' => $selected, 'subdistrict' => $subdistrict,
            'placeTitle' => $title, 'facts' => $places->facts($selected), 'directory' => $places->services()]);
    }
}
