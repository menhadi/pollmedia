<?php

namespace App\Http\Controllers;

use App\Services\LocalBoard;
use App\Services\LocalBoardLocations;
use App\Services\MyPlaceDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class LocalBoardController extends Controller
{
    public function index(Request $request, LocalBoard $board, LocalBoardLocations $locations): View
    {
        $input = $request->validate(['area' => 'nullable|string|max:100', 'kind' => 'nullable|in:job,service', 'q' => 'nullable|string|max:100', 'category' => ['nullable', Rule::in(array_keys(LocalBoard::CATEGORIES))]]);
        $area = ! empty($input['area']) ? $locations->resolve($input['area']) : null;
        $query = $board->forArea($board->active(), $area);
        foreach (['kind', 'category'] as $field) {
            if (! empty($input[$field])) {
                $query->where($field, $input[$field]);
            }
        }
        if (! empty($input['q'])) {
            $query->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($input['q']).'%']);
        }
        $listings = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        return view('local-board-index', compact('listings', 'area') + ['states' => app(MyPlaceDirectory::class)->states()]);
    }

    public function places(Request $request, LocalBoardLocations $locations): JsonResponse
    {
        $input = $request->validate(['kind' => 'required|in:states,districts,village,gp,city', 'state' => 'nullable|regex:/^[1-9][0-9]{0,2}$/', 'district' => 'nullable|regex:/^[1-9][0-9]{0,7}$/', 'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1|max:10000']);

        return response()->json($locations->options($input['kind'], $input['state'] ?? '', $input['district'] ?? '', trim($input['q'] ?? ''), (int) ($input['page'] ?? 1)));
    }

    public function create(Request $request, LocalBoardLocations $locations): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->guest(route('community.login', ['next' => $request->getRequestUri()]));
        }
        abort_unless($request->user()->email_verified_at, 403);
        $input = $request->validate(['area' => 'nullable|string|max:100']);
        $area = $this->formArea($request, $locations, $input['area'] ?? null);

        return view('local-board-form', ['listing' => null, 'area' => $area, 'states' => app(MyPlaceDirectory::class)->states()]);
    }

    public function store(Request $request, LocalBoard $board, LocalBoardLocations $locations): RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->guest(route('community.login', ['next' => route('local-board.create', [], false)]));
        }
        abort_unless($request->user()->email_verified_at, 403);
        [$values, $area] = $this->validated($request, $locations);
        $id = (string) Str::ulid();
        DB::transaction(function () use ($request, $board, $values, $area, $id): void {
            abort_if(DB::table('local_board_listings')->where('user_id', $request->user()->id)->where('primary_area_key', $area['key'])->where('title', $values['title'])->whereIn('status', ['pending', 'approved'])->where('expires_at', '>', now())->exists(), 409, 'You already have an active or pending listing with this title here.');
            DB::table('local_board_listings')->insert($values + ['id' => $id, 'user_id' => $request->user()->id, 'status' => 'pending', 'revision' => 1, 'contact_consent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $board->saveScopes($id, $area);
            $board->event($id, $request->user()->id, 'submitted', null, 'pending');
        });

        return redirect()->route('local-board.mine')->with('status', 'Submitted for review. It will appear publicly after approval.');
    }

    public function mine(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->guest(route('community.login', ['next' => route('local-board.mine', [], false)]));
        }
        $listings = DB::table('local_board_listings')->where('user_id', $request->user()->id)->orderByDesc('created_at')->paginate(20);

        return view('local-board-mine', compact('listings'));
    }

    public function show(Request $request, LocalBoard $board, string $listing): View
    {
        $entry = DB::table('local_board_listings')->find($listing) ?? abort(404);
        $owner = $request->user() && (int) $entry->user_id === (int) $request->user()->id;
        abort_unless($board->visible($entry) || $owner || $request->user()?->is_admin, 404);
        $scopes = DB::table('local_board_scopes')->where('listing_id', $listing)->get();

        return view('local-board-show', compact('entry', 'owner', 'scopes') + ['active' => $board->visible($entry)]);
    }

    public function edit(Request $request, LocalBoardLocations $locations, string $listing): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->guest(route('community.login', ['next' => $request->getRequestUri()]));
        }
        $entry = DB::table('local_board_listings')->find($listing) ?? abort(404);
        abort_unless((int) $entry->user_id === (int) $request->user()->id && $request->user()->email_verified_at, 403);

        return view('local-board-form', ['listing' => $entry, 'area' => $this->formArea($request, $locations, $entry->primary_area_key), 'states' => app(MyPlaceDirectory::class)->states()]);
    }

    public function update(Request $request, LocalBoard $board, LocalBoardLocations $locations, string $listing): RedirectResponse
    {
        abort_unless($request->user()?->email_verified_at, 403);
        [$values, $area] = $this->validated($request, $locations);
        $request->validate(['revision' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $board, $listing, $values, $area): void {
            $entry = DB::table('local_board_listings')->where('id', $listing)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $entry->user_id === (int) $request->user()->id, 403);
            abort_unless((int) $entry->revision === (int) $request->integer('revision'), 409, 'This listing changed. Reload before saving.');
            DB::table('local_board_listings')->where('id', $listing)->update($values + ['status' => 'pending', 'reviewer_id' => null, 'reviewed_at' => null, 'verification_method' => null, 'review_message' => null, 'revision' => $entry->revision + 1, 'contact_consent_at' => now(), 'updated_at' => now()]);
            $board->saveScopes($listing, $area);
            $board->event($listing, $request->user()->id, 'updated', $entry->status, 'pending');
        });

        return redirect()->route('local-board.mine')->with('status', 'Changes saved and sent for a fresh review.');
    }

    public function close(Request $request, LocalBoard $board, string $listing): RedirectResponse
    {
        abort_unless($request->user(), 403);
        $request->validate(['revision' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $board, $listing): void {
            $entry = DB::table('local_board_listings')->where('id', $listing)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $entry->user_id === (int) $request->user()->id, 403);
            abort_unless((int) $entry->revision === (int) $request->integer('revision'), 409);
            DB::table('local_board_listings')->where('id', $listing)->update(['status' => 'closed', 'revision' => $entry->revision + 1, 'updated_at' => now()]);
            $board->event($listing, $request->user()->id, 'closed', $entry->status, 'closed');
        });

        return redirect()->route('local-board.mine')->with('status', 'Listing closed.');
    }

    public function review(Request $request): View
    {
        $input = $request->validate(['status' => 'nullable|in:pending,approved,rejected,closed,expired']);
        $status = $input['status'] ?? 'pending';
        $query = DB::table('local_board_listings as l')->join('users as u', 'u.id', '=', 'l.user_id')->select('l.*', 'u.email as poster_email', 'u.email_verified_at as poster_verified_at');
        if ($status === 'expired') {
            $query->where('l.expires_at', '<=', now());
        } else {
            $query->where('l.status', $status);
        }
        $listings = $query->orderBy('l.created_at')->paginate(20)->withQueryString();

        return view('local-board-review', compact('listings', 'status'));
    }

    public function decide(Request $request, LocalBoard $board, string $listing): RedirectResponse
    {
        $input = $request->validate(['decision' => 'required|in:approved,rejected', 'revision' => 'required|integer|min:1', 'review_note' => 'required|string|min:10|max:2000', 'verification_method' => ['nullable', 'required_if:decision,approved', Rule::in(array_keys(LocalBoard::METHODS))], 'review_message' => 'nullable|required_if:decision,rejected|string|min:10|max:500']);
        DB::transaction(function () use ($request, $board, $listing, $input): void {
            $entry = DB::table('local_board_listings')->where('id', $listing)->lockForUpdate()->first() ?? abort(404);
            abort_unless($entry->status === 'pending' && (int) $entry->revision === (int) $input['revision'], 409, 'The submission changed. Review its latest version.');
            abort_unless(Carbon::parse($entry->expires_at)->isFuture(), 422, 'This listing expired. The poster must update it.');
            abort_unless(DB::table('users')->where('id', $entry->user_id)->whereNotNull('email_verified_at')->exists(), 422, 'The poster email is not verified.');
            $method = $input['decision'] === 'approved' ? $input['verification_method'] : null;
            DB::table('local_board_listings')->where('id', $listing)->update(['status' => $input['decision'], 'reviewer_id' => $request->user()->id, 'reviewed_at' => now(), 'verification_method' => $method, 'review_message' => $input['review_message'] ?? null, 'revision' => $entry->revision + 1, 'updated_at' => now()]);
            $board->event($listing, $request->user()->id, 'reviewed', $entry->status, $input['decision'], $input['review_note'], $method);
        });

        return back()->with('status', 'Review saved.');
    }

    private function formArea(Request $request, LocalBoardLocations $locations, ?string $fallback): ?array
    {
        if ($request->session()->hasOldInput('area')) {
            $key = $request->old('area');
            if (! is_string($key) || $key === '') {
                return null;
            }
            try {
                return $locations->resolve($key);
            } catch (HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() !== 404) {
                    throw $exception;
                }

                return null;
            }
        }

        return $fallback ? $locations->resolve($fallback) : null;
    }

    private function validated(Request $request, LocalBoardLocations $locations): array
    {
        $input = $request->validate([
            'area' => 'required|string|max:100', 'kind' => 'required|in:job,service',
            'category' => ['required', Rule::in(array_keys(LocalBoard::CATEGORIES))],
            'title' => 'required|string|min:5|max:140', 'description' => 'required|string|min:20|max:3000',
            'organisation' => 'required|string|min:2|max:120', 'pay_text' => 'nullable|required_if:kind,job|string|max:120',
            'contact_name' => 'required|string|min:2|max:80',
            'contact_phone' => ['nullable', 'string', 'required_without:contact_email', 'regex:/^\\+?[0-9][0-9 ()-]{6,19}$/'],
            'contact_email' => 'nullable|required_without:contact_phone|email:rfc|max:254',
            'expires_on' => 'required|date_format:Y-m-d|after_or_equal:'.now('Asia/Kolkata')->toDateString().'|before_or_equal:'.now('Asia/Kolkata')->addDays(60)->toDateString(),
            'contact_consent' => 'accepted',
        ]);
        $area = $locations->resolve($input['area']);
        $values = array_intersect_key($input, array_flip(['kind', 'category', 'title', 'description', 'organisation', 'pay_text', 'contact_name', 'contact_phone', 'contact_email']));
        foreach ($values as $key => $value) {
            $values[$key] = is_string($value) ? trim($value) : $value;
        }
        $values += ['primary_area_key' => $area['key'], 'area_name' => $area['name'], 'area_kind' => $area['kind'], 'expires_at' => Carbon::parse($input['expires_on'], 'Asia/Kolkata')->endOfDay()->utc()];

        return [$values, $area];
    }
}
