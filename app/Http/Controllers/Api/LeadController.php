<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function store(Request $request, string $type)
    {
        $rules = match ($type) {
            'contact' => [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email'],
                'phone' => ['required', 'string', 'max:40'],
                'message' => ['required', 'string', 'max:5000'],
            ],
            'quote' => [
                'first_name' => ['required', 'string', 'max:80'],
                'last_name' => ['required', 'string', 'max:80'],
                'cellphone' => ['required', 'string', 'max:40'],
                'landline' => ['nullable', 'string', 'max:40'],
                'state' => ['required', 'string', 'max:40'],
                'gender' => ['required', 'in:Male,Female'],
                'dob' => ['required', 'date'],
                'height_ft' => ['required', 'integer', 'between:4,7'],
                'height_in' => ['required', 'integer', 'between:0,11'],
                'weight' => ['required', 'numeric', 'min:50', 'max:700'],
                'face_amount' => ['required', 'string', 'max:40'],
                'term_length' => ['required', 'string', 'max:40'],
                'health_class' => ['required', 'string', 'max:40'],
                'email' => ['required', 'email'],
                'confirm_email' => ['required', 'same:email'],
                'tobacco' => ['required', 'in:Yes,No'],
                'exam' => ['required', 'in:Medical,Non-Medical'],
                'disability' => ['required', 'in:Yes,No'],
                'conditions' => ['nullable', 'array'],
                'conditions.*' => ['string', 'max:80'],
            ],
            'rate' => [
                'phone' => ['required', 'string', 'max:40'],
                'email' => ['required', 'email'],
                'state' => ['required', 'string', 'max:40'],
                'gender' => ['required', 'in:Male,Female'],
                'dob' => ['required', 'date'],
                'coverage' => ['required', 'numeric', 'min:1'],
                'tobacco' => ['required', 'in:Yes,No'],
            ],
            'schedule' => [
                'name' => ['required', 'string', 'max:120'],
                'cellphone' => ['required', 'string', 'max:40'],
                'landline' => ['nullable', 'string', 'max:40'],
                'email' => ['required', 'email'],
                'confirm_email' => ['required', 'same:email'],
                'date' => ['required', 'date'],
                'time' => ['required', 'string', 'max:20'],
            ],
            default => abort(404),
        };

        $data = $request->validate($rules);

        $lead = Lead::create([
            'type' => $type,
            'name' => $data['name'] ?? trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? $data['cellphone'] ?? null,
            'message' => $data['message'] ?? null,
            'payload' => $data,
        ]);

        return response()->json(['id' => $lead->id], 201);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('permissions');

        $allowed = collect(['contact', 'quote', 'rate', 'schedule', 'chat'])
            ->filter(fn (string $type) => $user->hasPermission('leads.'.$type))
            ->values();

        $query = Lead::query()->latest();

        if (! $user->is_super_admin) {
            $query->whereIn('type', $allowed);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json(['data' => $query->paginate(25)]);
    }

    public function update(Request $request, Lead $lead)
    {
        $user = $request->user();
        $user->loadMissing('permissions');

        if (! $user->hasPermission('leads.'.$lead->type)) {
            abort(403);
        }

        $data = $request->validate([
            'status' => ['required', 'in:new,contacted,closed'],
        ]);

        $lead->update($data);

        return response()->json(['data' => $lead]);
    }
}
