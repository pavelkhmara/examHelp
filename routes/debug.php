<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LanguageApp\ExamController;
use Illuminate\Http\Request;
use App\Models\GenerationTask;

Route::get('/debug/tasks/{id}', function ($id) {
    $t = GenerationTask::findOrFail($id);
    return response()->json([
        'status' => $t->status,
        'error'  => $t->error,
        'result' => $t->result,
    ]);
});
