const form = document.getElementById('chat-form');
const chatbox = document.getElementById('chatbox');
const input = document.getElementById('user-input');

// Permitir enviar con Enter
input.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    form.requestSubmit();
  }
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const message = input.value.trim();
  if (!message) return;

  // Mostrar mensaje del usuario
  chatbox.innerHTML += `
    <div class="text-right">
      <div class="inline-block bg-red-600 text-white px-4 py-2 rounded-2xl shadow-md">${message}</div>
    </div>
  `;
  chatbox.scrollTop = chatbox.scrollHeight;

  try {
    const res = await fetch('/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message })
    });

    const data = await res.json();
    const respuesta = data.reply || "❌ Error al obtener respuesta del servidor.";

    chatbox.innerHTML += `
      <div class="text-left">
        <div class="inline-block bg-gray-800 text-gray-200 px-4 py-2 rounded-2xl border border-red-600">
          ${respuesta}
        </div>
      </div>
    `;
  } catch (err) {
    chatbox.innerHTML += `
      <div class="text-left text-red-400 italic">⚠️ No se pudo conectar con el servidor.</div>
    `;
  }

  chatbox.scrollTop = chatbox.scrollHeight;
  input.value = '';
});

