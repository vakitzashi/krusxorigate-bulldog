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
let toastTimer = 0;
const showToast = (title, message, type = 'success') => {
  clearTimeout(toastTimer);
  toast.querySelector('b').textContent = title;
  toast.querySelector('span').textContent = message;
  toast.classList.toggle('is-error', type === 'error');
  toast.classList.add('is-visible');
  toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 6200);
};
const createOrderKey = () => {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return `${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`;
};
let orderKey = createOrderKey();
form.addEventListener('submit', async event => {
  event.preventDefault();
  if (!form.reportValidity()) return;

  const button = form.querySelector('button[type="submit"]');
  const originalButton = button.innerHTML;
  const data = Object.fromEntries(new FormData(form).entries());
  data.idempotency_key = orderKey;
  button.disabled = true;
  button.innerHTML = 'Отправляем… <span>→</span>';
  form.setAttribute('aria-busy', 'true');

  try {
    const response = await fetch('order.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(data),
      credentials: 'same-origin'
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.ok) throw new Error(result.message || 'Не удалось отправить заявку. Попробуйте ещё раз.');

    showToast('Заказ № ' + result.order_number + ' оформлен', 'Заявка принята. Мы свяжемся с вами для подтверждения.');
    form.reset();
    orderKey = createOrderKey();
  } catch (error) {
    showToast('Заявка не отправлена', error.message || 'Проверьте соединение и повторите попытку.', 'error');
  } finally {
    button.disabled = false;
    button.innerHTML = originalButton;
    form.removeAttribute('aria-busy');
  }
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

const accessoriesCarousel = document.querySelector('.accessories__cards');
if (accessoriesCarousel) {
  const mobileCarouselQuery = window.matchMedia('(max-width: 680px)');
  const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  let carouselItems = [];
  let carouselClone = null;
  let carouselUi = null;
  let carouselDots = [];
  let carouselCount = null;
  let carouselTimer = 0;
  let carouselResumeTimer = 0;
  let carouselScrollTimer = 0;
  let carouselObserver = null;
  let carouselVisible = false;
  let carouselInitialized = false;
  let carouselProgrammatic = false;

  const carouselStep = () => {
    const first = carouselItems[0];
    if (!first) return 0;
    const styles = getComputedStyle(accessoriesCarousel);
    const gap = parseFloat(styles.columnGap || styles.gap) || 0;
    return first.getBoundingClientRect().width + gap;
  };

  const currentCarouselIndex = () => {
    const step = carouselStep();
    if (!step) return 0;
    return Math.min(carouselItems.length, Math.max(0, Math.round(accessoriesCarousel.scrollLeft / step)));
  };

  const paintCarouselState = () => {
    if (!carouselItems.length) return;
    const rawIndex = currentCarouselIndex();
    const index = rawIndex >= carouselItems.length ? 0 : rawIndex;
    carouselItems.forEach((item, itemIndex) => item.classList.toggle('is-active', itemIndex === index));
    carouselDots.forEach((dot, dotIndex) => {
      const active = dotIndex === index;
      dot.classList.toggle('is-active', active);
      dot.setAttribute('aria-current', active ? 'true' : 'false');
    });
    if (carouselCount) {
      carouselCount.innerHTML = '<b>' + String(index + 1).padStart(2, '0') + '</b> / ' + String(carouselItems.length).padStart(2, '0');
    }
  };

  const stopCarousel = () => {
    clearTimeout(carouselTimer);
    clearTimeout(carouselResumeTimer);
    carouselTimer = 0;
    carouselResumeTimer = 0;
  };

  const scheduleCarousel = (delay = 4000) => {
    clearTimeout(carouselTimer);
    if (!carouselInitialized || !carouselVisible || reducedMotionQuery.matches) return;
    carouselTimer = window.setTimeout(advanceCarousel, delay);
  };

  const goToCarouselItem = (index, behavior = 'smooth') => {
    const step = carouselStep();
    if (!step) return;
    carouselProgrammatic = true;
    accessoriesCarousel.scrollTo({ left: step * index, behavior });
    window.setTimeout(() => {
      carouselProgrammatic = false;
      paintCarouselState();
    }, behavior === 'smooth' ? 650 : 0);
  };

  function advanceCarousel() {
    if (!carouselInitialized || !carouselVisible || reducedMotionQuery.matches) return;
    const next = currentCarouselIndex() + 1;
    goToCarouselItem(next);
    if (next >= carouselItems.length) {
      window.setTimeout(() => {
        if (!carouselInitialized) return;
        goToCarouselItem(0, 'auto');
        scheduleCarousel(4000);
      }, 720);
    } else {
      scheduleCarousel(4000);
    }
  }

  const pauseCarouselForSwipe = () => {
    if (!carouselInitialized) return;
    carouselProgrammatic = false;
    stopCarousel();
  };

  const resumeCarouselAfterSwipe = () => {
    if (!carouselInitialized) return;
    clearTimeout(carouselResumeTimer);
    carouselResumeTimer = window.setTimeout(() => scheduleCarousel(0), 6000);
  };

  const handleCarouselScroll = () => {
    if (!carouselInitialized) return;
    paintCarouselState();
    clearTimeout(carouselScrollTimer);
    carouselScrollTimer = window.setTimeout(() => {
      if (currentCarouselIndex() >= carouselItems.length) goToCarouselItem(0, 'auto');
      paintCarouselState();
      if (!carouselProgrammatic && !carouselTimer && !carouselResumeTimer) scheduleCarousel(4000);
    }, 160);
  };

  const handleCarouselKey = event => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    pauseCarouselForSwipe();
    const direction = event.key === 'ArrowRight' ? 1 : -1;
    const next = (currentCarouselIndex() + direction + carouselItems.length) % carouselItems.length;
    goToCarouselItem(next);
    resumeCarouselAfterSwipe();
  };

  const initAccessoriesCarousel = () => {
    if (carouselInitialized || !mobileCarouselQuery.matches) return;
    carouselItems = [...accessoriesCarousel.querySelectorAll(':scope > article:not(.is-carousel-clone)')];
    if (carouselItems.length < 2) return;
    carouselInitialized = true;
    accessoriesCarousel.setAttribute('role', 'region');
    accessoriesCarousel.setAttribute('aria-label', 'Карусель аксессуаров');
    accessoriesCarousel.setAttribute('tabindex', '0');

    carouselClone = carouselItems[0].cloneNode(true);
    carouselClone.classList.add('is-carousel-clone');
    carouselClone.setAttribute('aria-hidden', 'true');
    accessoriesCarousel.append(carouselClone);

    carouselUi = document.createElement('div');
    carouselUi.className = 'accessories__carousel-ui';
    carouselCount = document.createElement('span');
    carouselCount.className = 'accessories__carousel-count';
    const dots = document.createElement('div');
    dots.className = 'accessories__carousel-dots';
    dots.setAttribute('aria-label', 'Выбор аксессуара');
    carouselDots = carouselItems.map((item, index) => {
      const dot = document.createElement('button');
      dot.className = 'accessories__carousel-dot';
      dot.type = 'button';
      dot.setAttribute('aria-label', 'Показать аксессуар ' + (index + 1));
      dot.addEventListener('click', () => {
        pauseCarouselForSwipe();
        goToCarouselItem(index);
        resumeCarouselAfterSwipe();
      });
      dots.append(dot);
      return dot;
    });
    carouselUi.append(carouselCount, dots);
    accessoriesCarousel.after(carouselUi);

    accessoriesCarousel.addEventListener('pointerdown', pauseCarouselForSwipe, { passive: true });
    accessoriesCarousel.addEventListener('pointerup', resumeCarouselAfterSwipe, { passive: true });
    accessoriesCarousel.addEventListener('pointercancel', resumeCarouselAfterSwipe, { passive: true });
    accessoriesCarousel.addEventListener('scroll', handleCarouselScroll, { passive: true });
    accessoriesCarousel.addEventListener('keydown', handleCarouselKey);

    carouselObserver = new IntersectionObserver(entries => {
      carouselVisible = entries[0]?.isIntersecting ?? false;
      if (carouselVisible) scheduleCarousel(3200);
      else stopCarousel();
    }, { threshold: .35 });
    carouselObserver.observe(accessoriesCarousel);
    paintCarouselState();
  };

  const destroyAccessoriesCarousel = () => {
    if (!carouselInitialized) return;
    stopCarousel();
    clearTimeout(carouselScrollTimer);
    carouselObserver?.disconnect();
    accessoriesCarousel.removeEventListener('pointerdown', pauseCarouselForSwipe);
    accessoriesCarousel.removeEventListener('pointerup', resumeCarouselAfterSwipe);
    accessoriesCarousel.removeEventListener('pointercancel', resumeCarouselAfterSwipe);
    accessoriesCarousel.removeEventListener('scroll', handleCarouselScroll);
    accessoriesCarousel.removeEventListener('keydown', handleCarouselKey);
    carouselClone?.remove();
    carouselUi?.remove();
    carouselItems.forEach(item => item.classList.remove('is-active'));
    accessoriesCarousel.removeAttribute('role');
    accessoriesCarousel.removeAttribute('aria-label');
    accessoriesCarousel.removeAttribute('tabindex');
    accessoriesCarousel.scrollLeft = 0;
    carouselItems = [];
    carouselDots = [];
    carouselClone = null;
    carouselUi = null;
    carouselCount = null;
    carouselObserver = null;
    carouselVisible = false;
    carouselInitialized = false;
    carouselProgrammatic = false;
  };

  const syncAccessoriesCarousel = () => {
    if (mobileCarouselQuery.matches) initAccessoriesCarousel();
    else destroyAccessoriesCarousel();
  };

  if (mobileCarouselQuery.addEventListener) mobileCarouselQuery.addEventListener('change', syncAccessoriesCarousel);
  else mobileCarouselQuery.addListener(syncAccessoriesCarousel);
  if (reducedMotionQuery.addEventListener) {
    reducedMotionQuery.addEventListener('change', () => {
      stopCarousel();
      if (!reducedMotionQuery.matches) scheduleCarousel(3200);
    });
  }
  syncAccessoriesCarousel();
}
