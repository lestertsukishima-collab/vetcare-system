<?php

namespace App\Http\Controllers;

use App\Services\ClinicAccess;
use App\Services\ClinicCommands;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function index(Request $request, string $section = 'dashboard'): View
    {
        app(ClinicAccess::class)->authorize($request->user(), $section);
        foreach (['search' => 150, 'status' => 30, 'date' => 10, 'patient' => 18] as $key => $limit) {
            $value = $request->query($key);
            if ($value === null) {
                $request->query->remove($key);

                continue;
            }
            abort_unless(is_string($value) && mb_strlen($value) <= $limit, 400, 'Invalid workspace filter.');
        }

        return view('workspace', ['section' => $section]);
    }

    public function store(Request $request, string $section, ClinicCommands $commands): RedirectResponse
    {
        $commands->store($request->user(), $section, $request->all());

        return back()->with('success', 'Saved successfully.');
    }

    public function update(Request $request, string $section, int $id, ClinicCommands $commands): RedirectResponse
    {
        $redirect = $commands->update($request->user(), $section, $id, $request->all());

        return $redirect ? redirect($redirect)->with('success', 'Account updated. Your workspace now follows your new role.') : back()->with('success', 'Updated successfully.');
    }
}
