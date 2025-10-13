<x-layout :title="'Attempt'">
  <div id="app" class="space-y-4">
    <div id="status" class="text-sm text-gray-500">Loading...</div>
    <div id="card" class="p-4 rounded bg-white shadow hidden">
      <div id="prompt" class="font-medium"></div>
      <div id="options" class="mt-3 space-y-2"></div>
      <textarea id="textAnswer" class="mt-3 w-full border rounded p-2 hidden" rows="4" placeholder="Type your answer"></textarea>
    </div>

    <div class="flex items-center gap-2">
      <button id="prevBtn" class="px-3 py-2 rounded bg-gray-200 hover:bg-gray-300" disabled>Prev</button>
      <button id="nextBtn" class="px-3 py-2 rounded bg-blue-600 text-white hover:bg-blue-700" disabled>Next</button>
      <button id="finishBtn" class="ml-auto px-3 py-2 rounded bg-green-600 text-white hover:bg-green-700" disabled>Finish</button>
    </div>

    <div id="result" class="p-4 rounded bg-green-50 border border-green-200 hidden"></div>
  </div>

  <script>
    const attemptId = @json($attemptId);
    let exam = null;
    let idx = 0;
    let sending = false;

    const el = (id) => document.getElementById(id);
    const status = el('status'), card = el('card'), prompt = el('prompt');
    const options = el('options'), textAnswer = el('textAnswer');
    const prevBtn = el('prevBtn'), nextBtn = el('nextBtn'), finishBtn = el('finishBtn'), result = el('result');

    async function loadAttempt() {
      // берём exam_id через небольшой хак: получим попытку из БД API? — у нас нет эндпоинта.
      // Обойдёмся: попросим пользователя зайти сюда со страницы show, поэтому просто попросим exam_id из истории?
      // Проще: сохраним exam при старте (из show) — но сейчас его нет. Тогда сделаем другой путь:
      // Мы сможем вытянуть exam, записав его в sessionStorage на предыдущем шаге. Если пусто — запросим список и попросим выбрать.
      // Для простоты: читаем exam через referer недоступно; поэтому добавим мини-API-обход: запросим последний активный экзамен.
      // Однако корректнее: примем examId через query (?exam=ID). Упростим сейчас:
      status.textContent = 'Loading exam...';

      const params = new URLSearchParams(location.search);
      const examId = params.get('exam');
      if (!examId) { status.textContent = 'Missing exam id'; return; }

      const res = await fetch('/api/exams/' + examId);
      if (!res.ok) { status.textContent = 'Exam not found'; return; }

      const j = await res.json();
      exam = j.data;

      status.textContent = '';
      render();
    }

    function render() {
      if (!exam) return;
      const qs = exam.questions || [];
      const q = qs[idx];
      if (!q) { status.textContent = 'No questions.'; return; }

      card.classList.remove('hidden');
      prompt.textContent = `${idx+1}. ${q.prompt}`;
      options.innerHTML = '';
      textAnswer.classList.add('hidden');
      textAnswer.value = '';

      if (q.type === 'MCQ') {
        (q.options || []).forEach(opt => {
          const label = document.createElement('label');
          label.className = 'flex items-center gap-2';
          const input = document.createElement('input');
          input.type = 'radio';
          input.name = 'opt';
          input.value = opt.id;
          label.appendChild(input);
          label.appendChild(document.createTextNode(opt.text));
          options.appendChild(label);
        });
      } else {
        textAnswer.classList.remove('hidden');
      }

      prevBtn.disabled = idx === 0;
      nextBtn.disabled = idx >= qs.length - 1;
      finishBtn.disabled = false;
    }

    async function saveAnswer() {
      if (sending) return;
      sending = true;
      prevBtn.disabled = nextBtn.disabled = finishBtn.disabled = true;

      const q = exam.questions[idx];
      let payload = { question_id: q.id, type: q.type };
      if (q.type === 'MCQ') {
        const checked = document.querySelector('input[name="opt"]:checked');
        if (!checked) { sending = false; return; }
        payload.selected_option_id = checked.value;
      } else {
        payload.text_answer = textAnswer.value || '';
      }

      const res = await fetch(`/api/attempts/${attemptId}/answers`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      sending = false;
      if (!res.ok) { alert('Save failed'); }
      
      prevBtn.disabled = (idx === 0);
      nextBtn.disabled = (idx >= exam.questions.length - 1);
      finishBtn.disabled = false;
    }

    prevBtn.addEventListener('click', async () => { await saveAnswer(); idx = Math.max(0, idx-1); render(); });
    nextBtn.addEventListener('click', async () => { await saveAnswer(); idx = Math.min(exam.questions.length-1, idx+1); render(); });
    finishBtn.addEventListener('click', async () => {
      await saveAnswer();
      const res = await fetch(`/api/attempts/${attemptId}/complete`, { method: 'POST' });
      if (!res.ok) { alert('Complete failed'); return; }
      const data = await res.json();
      const score = data?.data?.score ?? 'N/A';
      location.href = `/attempts/${attemptId}/result?score=${encodeURIComponent(score)}`;
    });

    loadAttempt();
  </script>
</x-layout>
