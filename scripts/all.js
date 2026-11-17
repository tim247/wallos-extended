if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('service-worker.php').then(function(registration) {
      //console.log('ServiceWorker registration successful with scope: ', registration.scope);
    }, function(err) {
      console.log('ServiceWorker registration failed: ', err);
    });
  });
}
// "Install app": the browser's install prompt where it offers one, otherwise a short
// explanation (iPhone: share menu; plain http: browsers only install over HTTPS).
(function () {
  let deferredPrompt = null;
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
  });
  window.addEventListener('appinstalled', () => { deferredPrompt = null; });

  document.addEventListener('DOMContentLoaded', () => {
    const link = document.querySelector('a[href="#install-app"]');
    if (!link) return;
    if (standalone) {
      link.remove(); // already running as the installed app
      return;
    }
    link.addEventListener('click', (event) => {
      event.preventDefault();
      if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.finally(() => { deferredPrompt = null; });
        return;
      }
      const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
      const key = !window.isSecureContext ? 'install_app_needs_https' : (ios ? 'install_app_ios' : 'install_app_browser');
      alert(translate(key));
    });
  });
})();
