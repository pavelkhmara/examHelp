<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{{ $title ?? 'Exam Simulator' }}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
  <div class="max-w-3xl mx-auto p-6">
    <a href="/exams" class="text-sm text-blue-600 hover:underline">← Exams</a>
    <h1 class="text-2xl font-bold mt-2 mb-6">{{ $title ?? '' }}</h1>
    {{ $slot }}
  </div>
</body>
</html>
