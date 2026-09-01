<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavigationLabelController extends Controller
{
    /** @var list<string> */
    private const KEYS = [
        'dashboard', 'chats', 'contacts', 'catalog',
        'pipeline', 'tasks', 'broadcasts', 'settings',
    ];

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('navigation_labels.manage'), 403);

        $validated = $request->validate([
            'labels' => ['present', 'array'],
            'labels.*' => ['required', 'string'],
        ]);

        $unknownKeys = array_diff(array_keys($validated['labels']), self::KEYS);
        if ($unknownKeys !== []) {
            return response()->json([
                'message' => 'La navegación contiene secciones no reconocidas.',
                'errors' => ['labels' => ['La navegación contiene secciones no reconocidas.']],
            ], 422);
        }

        $labels = [];
        foreach ($validated['labels'] as $key => $label) {
            $label = trim($label);
            if ($label === '') {
                return response()->json([
                    'message' => 'Los nombres de navegación no pueden estar vacíos.',
                    'errors' => ["labels.{$key}" => ['El nombre no puede estar vacío.']],
                ], 422);
            }
            if (mb_strlen($label) > 30) {
                return response()->json([
                    'message' => 'Los nombres de navegación pueden tener hasta 30 caracteres.',
                    'errors' => ["labels.{$key}" => ['El nombre puede tener hasta 30 caracteres.']],
                ], 422);
            }
            $labels[$key] = $label;
        }

        $tenant = $request->user()->tenant;
        $tenant->navigation_labels = $labels === [] ? null : $labels;
        $tenant->save();

        return response()->json([
            'data' => ['labels' => $tenant->navigation_labels ?? []],
        ]);
    }
}
