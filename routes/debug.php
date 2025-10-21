<?php

use App\Models\GenerationTask;
use Illuminate\Support\Facades\Route;

Route::get('/debug/tasks/{id}', function ($id) {
    $t = GenerationTask::findOrFail($id);

    return response()->json([
        'status' => $t->status,
        'error' => $t->error,
        'result' => $t->result,
    ]);
});
