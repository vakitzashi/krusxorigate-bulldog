const modes = {
  ball: { badge: 'ОСНОВНОЙ РЕЖИМ', title: 'Точная тренировочная стрельба', text: 'В барабан устанавливаются совместимые монтажные патроны, а с передней части — резиновые шары диаметром 10 мм. Используйте только в специально предназначенных и безопасных условиях.', value: '10 мм', label: 'Диаметр резинового шара', image: 'mode-rubber.webp', alt: 'БУЛЬДОГ KURS в режиме стрельбы резиновыми шарами' },
  blank: { badge: 'ЗВУКОВОЙ СИГНАЛ', title: 'Холостой выстрел до 120 дБ', text: 'БУЛЬДОГ можно использовать без резиновых шаров — только с совместимыми монтажными патронами. Обязательно используйте средства защиты слуха и соблюдайте дистанцию.', value: '120 дБ', label: 'Громкость холостого режима', image: 'mode-blank.webp', alt: 'БУЛЬДОГ KURS в холостом режиме' },
  firework: { badge: 'ДОПОЛНИТЕЛЬНАЯ ОПЦИЯ', title: 'Запуск мини-фейерверков', text: 'Стальная насадка-мортирка устанавливается в ствол и поддерживает совместимые мини-фейерверки KURS. Насадка и фейерверки приобретаются отдельно.', value: '3 в 1', label: 'Многофункциональный формат', image: 'mode-fireworks.webp', alt: 'Насадка-мортирка и мини-фейерверки KURS' }
};

const ageGate = document.querySelector('#ageGate');
const unlock = () => { ageGate.classList.add('is-hidden'); document.body.classList.remove('age-locked'); sessionStorage.setItem('bulldog-age-ok', '1'); };
if (sessionStorage.getItem('bulldog-age-ok') === '1') unlock();
document.querySelector('#ageYes').addEventListener('click', unlock);
document.querySelector('#ageNo').addEventListener('click', () => { window.location.href = 'https://www.google.ru/'; });

const observer = new IntersectionObserver((entries) => entries.forEach(entry => {
  if (!entry.isIntersecting) return;
  entry.target.classList.add('is-visible');
  if (entry.target.matches('.intro__stats')) animateCounters(entry.target);
  observer.unobserve(entry.target);
}), { threshold: .12 });
document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

function animateCounters(root) {
  root.querySelectorAll('[data-count]').forEach(el => {
    const end = Number(el.dataset.count); const start = performance.now();
    const tick = now => { const p = Math.min((now - start) / 1100, 1); el.textContent = Math.round(end * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  });
}

const header = document.querySelector('#header'); const progress = document.querySelector('.scroll-progress span');
window.addEventListener('scroll', () => {
  header.classList.toggle('is-scrolled', scrollY > 30);
  const max = document.documentElement.scrollHeight - innerHeight;
  progress.style.width = `${max ? scrollY / max * 100 : 0}%`;
}, { passive: true });

document.querySelectorAll('.mode-tab').forEach(tab => tab.addEventListener('click', () => {
  document.querySelectorAll('.mode-tab').forEach(t => { t.classList.remove('is-active'); t.setAttribute('aria-selected', 'false'); });
  tab.classList.add('is-active'); tab.setAttribute('aria-selected', 'true');
  const mode = modes[tab.dataset.mode]; const copy = document.querySelector('#modeCopy'); const image = document.querySelector('.modes__image img');
  copy.animate([{ opacity: 0, transform: 'translateY(10px)' }, { opacity: 1, transform: 'none' }], { duration: 380 });
  copy.innerHTML = `<span>${mode.badge}</span><h3>${mode.title}</h3><p>${mode.text}</p><div><b>${mode.value}</b><small>${mode.label}</small></div>`;
  image.style.opacity = '0'; setTimeout(() => { image.src = mode.image; image.alt = mode.alt; image.style.opacity = '1'; }, 220);
}));

const menuButton = document.querySelector('.menu-toggle'); const nav = document.querySelector('.nav');
const closeMenu = () => {
  nav.classList.remove('is-open');
  document.body.classList.remove('menu-open');
  menuButton.setAttribute('aria-expanded', 'false');
  menuButton.setAttribute('aria-label', 'Открыть меню');
};
menuButton.addEventListener('click', () => {
  const open = nav.classList.toggle('is-open');
  document.body.classList.toggle('menu-open', open);
  menuButton.setAttribute('aria-expanded', String(open));
  menuButton.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
});
nav.querySelectorAll('a').forEach(a => a.addEventListener('click', closeMenu));
document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });
window.addEventListener('resize', () => { if (window.innerWidth > 980) closeMenu(); }, { passive: true });

document.querySelectorAll('.faq details').forEach(item => item.addEventListener('toggle', () => {
  if (!item.open) return;
  document.querySelectorAll('.faq details').forEach(other => { if (other !== item) other.removeAttribute('open'); });
}));

const gallery = document.querySelector('#gallery');
document.querySelectorAll('.js-open-gallery').forEach(button => button.addEventListener('click', () => {
  const img = button.querySelector('img'); if (img) gallery.querySelector('img').src = img.src;
  gallery.showModal();
}));
document.querySelector('.gallery__close').addEventListener('click', () => gallery.close());
gallery.addEventListener('click', event => { if (event.target === gallery) gallery.close(); });

const form = document.querySelector('#orderForm'); const toast = document.querySelector('#toast');
form.addEventListener('submit', event => {
  event.preventDefault();
  toast.classList.add('is-visible'); form.reset();
  setTimeout(() => toast.classList.remove('is-visible'), 4200);
});

const quickCylinder = document.querySelector('#quickCylinder');
if (quickCylinder) {
  const cylinderTrigger = quickCylinder.querySelector('.quick-cylinder__trigger');
  let cylinderFrame = 0;
  const updateCylinderRotation = () => {
    quickCylinder.style.setProperty('--scroll-rotation', `${window.scrollY * .32}deg`);
    cylinderFrame = 0;
  };
  window.addEventListener('scroll', () => {
    if (!cylinderFrame) cylinderFrame = requestAnimationFrame(updateCylinderRotation);
  }, { passive: true });
  updateCylinderRotation();

  cylinderTrigger.addEventListener('click', () => {
    const open = quickCylinder.classList.toggle('is-open');
    cylinderTrigger.setAttribute('aria-expanded', String(open));
    cylinderTrigger.setAttribute('aria-label', open ? 'Закрыть быстрые действия' : 'Открыть быстрые действия');
  });
  quickCylinder.querySelectorAll('.quick-cylinder__actions a').forEach(link => link.addEventListener('click', () => {
    quickCylinder.classList.remove('is-open');
    cylinderTrigger.setAttribute('aria-expanded', 'false');
    cylinderTrigger.setAttribute('aria-label', 'Открыть быстрые действия');
  }));
  document.addEventListener('pointerdown', event => {
    if (!quickCylinder.contains(event.target)) {
      quickCylinder.classList.remove('is-open');
      cylinderTrigger.setAttribute('aria-expanded', 'false');
      cylinderTrigger.setAttribute('aria-label', 'Открыть быстрые действия');
    }
  });
}
