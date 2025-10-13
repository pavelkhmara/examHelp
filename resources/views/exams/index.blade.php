<x-layout :title="'Exams'">
  @if(empty($exams))
    <div class="p-4 rounded bg-yellow-50 border border-yellow-200">No exams yet.</div>
  @else
    <ul class="space-y-3">
      @foreach($exams as $exam)
        <li class="p-4 rounded-lg bg-white shadow flex items-center justify-between">
          <div>
            <div class="font-semibold">{{ $exam['title'] }}</div>
            <div class="text-sm text-gray-500">Level: {{ $exam['level'] }}</div>
          </div>
          <a href="{{ route('exams.show', $exam['id']) }}"
             class="px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700">Open</a>
        </li>
      @endforeach
    </ul>
  @endif
</x-layout>
