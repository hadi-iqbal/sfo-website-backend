<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteExplorerController extends Controller
{
    public function show(): JsonResponse
    {
        $groups = DB::table('site_explorer_settings')->where('id', 1)->value('groups');

        return response()->json(['groups' => $groups === null ? null : json_decode($groups, true)]);
    }

    public function publish(Request $request): JsonResponse
    {
        $path = 'regex:~^/(?:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*)?$~D';
        $target = 'regex:~^(?:ask-nirvana|speak-to-agent|/(?:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*)?(?:#[a-z0-9]+(?:-[a-z0-9]+)*)?)$~D';
        $validated = $request->validate([
            'groups' => ['present', 'array', 'list', 'max:8'],
            'groups.*' => ['array:label,items'],
            'groups.*.label' => ['required', 'string', 'max:48'],
            'groups.*.items' => ['present', 'array', 'list', 'max:20'],
            'groups.*.items.*' => ['array:label,icon,target,scope,visible'],
            'groups.*.items.*.label' => ['required', 'string', 'max:56'],
            'groups.*.items.*.icon' => ['required', 'in:compass,shield,calculator,users,info,briefcase,phone,message,help,arrow'],
            'groups.*.items.*.target' => ['required', 'string', 'max:160', $target, 'not_regex:~^/admin(?:/|#|$)~'],
            'groups.*.items.*.scope' => ['present', 'nullable', 'string', 'max:120', $path, 'not_regex:~^/admin(?:/|$)~'],
            'groups.*.items.*.visible' => ['required', 'boolean'],
        ]);

        DB::table('site_explorer_settings')->upsert(
            [['id' => 1, 'groups' => json_encode($validated['groups'], JSON_THROW_ON_ERROR)]],
            ['id'],
            ['groups']
        );

        return response()->json($validated);
    }
}
