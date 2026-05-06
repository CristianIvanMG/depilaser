/* =====================================================
   BellaNick Clinic — Chat / Bot widget
   - Inyecta el FAB y el panel del chat en <body>.
   - Conecta los listeners.
   - NO modifica la lógica funcional (resetSession, sessionStorage,
     ia_disabled, fallback a WhatsApp).
   - Si la página ya tiene #bella-chat o #chatToggle inline,
     no se duplica (idempotente).

   Uso por página:
     <link rel="stylesheet" href="/assets/css/chat.css">
     ...
     <script src="/assets/js/chat.js" defer></script>
===================================================== */
(function () {
  'use strict';

  // Idempotencia: si ya existe el chat (inline en la página), no lo dupliques.
  if (document.getElementById('bella-chat') || document.getElementById('chatToggle')) {
    return;
  }

  function buildWidget() {
    var fab = document.createElement('button');
    fab.id = 'chatToggle';
    fab.className = 'whatsapp-flotante';
    fab.setAttribute('aria-label', 'Abrir chat');
    fab.type = 'button';
    fab.textContent = '💬';

    var panel = document.createElement('div');
    panel.id = 'bella-chat';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-labelledby', 'chatTitle');
    panel.setAttribute('aria-modal', 'false');
    panel.innerHTML = ''
      + '<header class="chat-head">'
      +   '<div class="chat-head__avatar" aria-hidden="true">P</div>'
      +   '<div class="chat-head__title">'
      +     '<strong id="chatTitle">Paola · BellaNick Clinic</strong>'
      +     '<span>En línea · Responde rápido</span>'
      +   '</div>'
      +   '<div class="chat-head__spacer"></div>'
      +   '<button id="chatClose" class="chat-close" type="button" aria-label="Cerrar chat">✕</button>'
      + '</header>'
      + '<div id="messages" aria-live="polite"></div>'
      + '<div class="chat-input">'
      +   '<input type="text" id="userInput" placeholder="Escribe tu mensaje…" aria-label="Escribe tu pregunta" autocomplete="off" />'
      +   '<button id="sendBtn" type="button">Enviar</button>'
      + '</div>';

    document.body.appendChild(fab);
    document.body.appendChild(panel);
  }

  function wire() {
    var chat      = document.getElementById('bella-chat');
    var toggleBtn = document.getElementById('chatToggle');
    var closeBtn  = document.getElementById('chatClose');
    var sendBtn   = document.getElementById('sendBtn');
    var input     = document.getElementById('userInput');
    var messages  = document.getElementById('messages');
    if (!chat || !toggleBtn || !closeBtn || !sendBtn || !input || !messages) return;

    var iaDisponible = true;

    // Si la página se abre con file:// (doble-clic local) no podemos llamar a /php/*.
    // Cortamos la red en ese caso para evitar errores CORS sin romper la UI.
    function chatBackendOffline() {
      return location.protocol === 'file:' || !location.host;
    }

    function addMessage(type, text) {
      var div = document.createElement('div');
      div.className = type;
      div.innerText = text;
      messages.appendChild(div);
      messages.scrollTop = messages.scrollHeight;
    }

    function openChat() {
      chat.classList.add('activo');
      if (!messages.innerHTML) {
        addMessage('bella', '👋 Bienvenida a BellaNick Clinic. ¿Te ayudo a agendar una cita o resolver una duda?');
      }
      setTimeout(function () { input.focus(); }, 100);
    }

    function closeChat() {
      chat.classList.remove('activo');
      messages.innerHTML = '';
      resetSession();
      try { sessionStorage.setItem('chatClosed', 'true'); } catch (e) {}
    }

    toggleBtn.addEventListener('click', openChat);
    closeBtn.addEventListener('click', function (e) { e.stopPropagation(); closeChat(); });

    // Auto-abrir al cargar (solo desktop, una vez por sesión)
    window.addEventListener('load', function () {
      var alreadyClosed = false;
      try { alreadyClosed = !!sessionStorage.getItem('chatClosed'); } catch (e) {}
      if (!alreadyClosed && window.innerWidth > 480) {
        setTimeout(openChat, 1200);
      }
    });

    async function sendMessage() {
      if (!iaDisponible) { window.open('https://wa.me/525535433490', '_blank'); return; }
      var text = input.value.trim();
      if (!text) return;

      var wantsWA = /\b(whatsapp|wa|wha|por whatsapp|mandame whatsapp|quiero whatsapp)\b/i.test(text);
      addMessage('user', text);
      input.value = '';

      // Vista local (file://): no hay backend, mostrar mensaje amable y salir.
      if (chatBackendOffline()) {
        addMessage('bella', 'Para chatear con Paola, visita el sitio en https://depilasermexico.com 💖');
        return;
      }

      addMessage('bella', 'Escribiendo…');

      try {
        var res = await fetch('/php/chat.php?ts=' + Date.now(), {
          method: 'POST', credentials: 'same-origin', cache: 'no-store',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message: text })
        });
        var data = JSON.parse(await res.text());
        if (messages.lastChild) messages.lastChild.remove();
        addMessage('bella', data.reply);

        if (data.ia_disabled) {
          iaDisponible = false;
          closeChat();
          setTimeout(function () { window.open('https://wa.me/525535433490', '_blank'); }, 1200);
          return;
        }
        if (wantsWA) {
          closeChat();
          setTimeout(function () { window.open('https://wa.me/525535433490', '_blank'); }, 800);
        }
      } catch (e) {
        addMessage('bella', 'Hubo un problema técnico. ¿Prefieres que te atiendan por WhatsApp?');
      }
    }

    sendBtn.addEventListener('click', sendMessage);
    input.addEventListener('keypress', function (e) { if (e.key === 'Enter') sendMessage(); });

    async function resetSession() {
      // En file:// no hay servidor que resetear; evita el error CORS.
      if (chatBackendOffline()) return;
      try { await fetch('/php/reset_session.php', { method: 'POST', credentials: 'same-origin', cache: 'no-store' }); } catch (e) {}
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { buildWidget(); wire(); });
  } else {
    buildWidget();
    wire();
  }
})();
