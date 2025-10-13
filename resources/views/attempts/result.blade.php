<x-layout :title="'Result'">
  @php $score = request('score'); @endphp
  <div class="p-6 rounded-lg bg-white shadow">
    <div class="text-xl font-semibold">Ваш результат</div>
    <div class="mt-2 text-3xl">{{ $score ?? 'N/A' }}%</div>
    <div class="mt-6 flex gap-2">
      <a href="/exams" class="px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700">К списку экзаменов</a>
    </div>
  </div>
</x-layout>
