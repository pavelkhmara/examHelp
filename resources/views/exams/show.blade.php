<x-layout :title="$exam['title'] ?? 'Exam'">
  <div class="space-y-4">
    <div class="p-4 rounded bg-white shadow">
      <div class="text-sm text-gray-500">Level: {{ $exam['level'] }}</div>
      @if(!empty($exam['description']))
        <p class="mt-2">{{ $exam['description'] }}</p>
      @endif
    </div>

    <button id="startBtn"
            class="px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700">
      Start attempt
    </button>
  </div>

  <script>
    const examId = @json($exam['id']);
    document.getElementById('startBtn').addEventListener('click', async () => {
      const res = await fetch('/api/attempts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ exam_id: examId }),
      });
      if (!res.ok) { alert('Failed to start attempt'); return; }
      const data = await res.json();
      const attemptId = data?.data?.id;
      if (attemptId) location.href = `/attempts/${attemptId}?exam=${encodeURIComponent(examId)}`;
    });
  </script>
</x-layout>
