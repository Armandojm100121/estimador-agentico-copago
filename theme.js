// theme.js  -  Interruptor de modo claro/oscuro (#12).
// Crea un botón flotante 🌙/☀️, cambia data-theme en <html> y RECUERDA la
// preferencia en localStorage. El tema inicial ya se aplica en <head> (sin
// parpadeo); aquí solo sincronizamos el botón y manejamos el clic.
(function () {
  var root = document.documentElement;

  function temaActual() {
    return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'claro';
  }

  function aplicar(tema) {
    if (tema === 'dark') { root.setAttribute('data-theme', 'dark'); }
    else { root.removeAttribute('data-theme'); }
    try { localStorage.setItem('tema', tema); } catch (e) {}
    if (btn) {
      var oscuro = tema === 'dark';
      btn.textContent = oscuro ? '☀️' : '🌙';
      btn.setAttribute('aria-label', oscuro ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
      btn.setAttribute('title', oscuro ? 'Modo claro' : 'Modo oscuro');
    }
  }

  var btn = document.createElement('button');
  btn.id = 'btn-tema';
  btn.type = 'button';
  btn.addEventListener('click', function () {
    aplicar(temaActual() === 'dark' ? 'claro' : 'dark');
  });

  function init() {
    document.body.appendChild(btn);
    aplicar(temaActual());   // sincroniza el icono con el tema ya aplicado
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
