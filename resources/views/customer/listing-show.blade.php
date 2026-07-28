<x-customer.layout :title="$listing->displayName() ?: 'Объявление №'.$listing->id">
    <a class="back" href="{{ $backUrl }}">&larr; Назад к каталогу</a>

    <header class="page-header">
        <h1>{{ $listing->displayName() ?: 'Объявление №'.$listing->id }}</h1>
        <p>{{ collect([$listing->type->getLabel(), $listing->category?->name, $listing->brand?->name])->filter()->unique()->implode(' · ') }}</p>
    </header>

    <div class="card">
        @if ($listing->photos->isNotEmpty())
            @php($photoCount = $listing->photos->count())
            <div id="listing-gallery" class="gallery{{ $photoCount === 1 ? ' gallery--single' : '' }}">
                <div class="gallery-frame">
                    <div class="gallery-stage" @if ($photoCount > 1) tabindex="0" role="group" aria-label="Фотографии объявления" @endif>
                        @foreach ($listing->photos as $photo)
                            {{-- Размытая копия кадра лежит подложкой под ним самим, поэтому вертикальное фото не обрезается. --}}
                            <div class="gallery-slide" style="--slide-photo: url('{{ $photo->cssUrl() }}')">
                                <img src="{{ $photo->url() }}"
                                     alt="{{ $photoCount === 1 ? 'Фото объявления' : 'Фото объявления '.$loop->iteration.' из '.$loop->count }}"
                                     decoding="async"
                                     @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                            </div>
                        @endforeach
                    </div>

                    @if ($photoCount > 1)
                        {{-- Без скрипта — честное «N фото»; со скриптом счётчик становится живым «3 / 8». --}}
                        <span class="gallery-count" aria-hidden="true">{{ $photoCount }} фото</span>
                        <button type="button" class="gallery-arrow gallery-arrow-prev" aria-label="Предыдущее фото">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke="#1e293b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <button type="button" class="gallery-arrow gallery-arrow-next" aria-label="Следующее фото">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="#1e293b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    @endif
                </div>

                @if ($photoCount > 1 && $photoCount <= 8)
                    <div class="gallery-dots">
                        @for ($i = 0; $i < $photoCount; $i++)
                            <button type="button" class="gallery-dot{{ $i === 0 ? ' is-active' : '' }}" data-index="{{ $i }}"
                                    aria-label="Фото {{ $i + 1 }}" @if ($i === 0) aria-current="true" @endif></button>
                        @endfor
                    </div>
                @elseif ($photoCount > 8)
                    {{-- Точек стало бы больше, чем помещается пальцем: длинный набор ведёт полоса прогресса. --}}
                    <div class="gallery-rail" aria-hidden="true">
                        <span class="gallery-rail-track"><span class="gallery-rail-fill"></span></span>
                    </div>
                @endif

                @if ($photoCount > 1)
                    <p class="muted gallery-hint">Фотографий: {{ $photoCount }} — листайте вбок.</p>
                @endif
            </div>
        @endif

        @if ($listing->description)
            <p class="listing-line prewrap">{{ $listing->description }}</p>
        @endif
        @if ($listing->locationLine())
            <p class="listing-line muted">{{ $listing->locationLine() }}</p>
        @endif
        @if ($listing->price)
            <p class="listing-line listing-price">{{ $listing->price }}</p>
        @endif
        @if ($listing->supplier->displayName())
            <p class="listing-line muted">Поставщик: {{ $listing->supplier->displayName() }}</p>
        @endif

        @if ($alreadyRequested)
            <div class="actions"><span class="badge badge-green">Заявка отправлена — ждём ответа поставщика</span></div>
        @else
            <form method="POST" action="{{ $selectUrl }}" class="actions">
                @csrf
                {{-- The filter state rides along so the confirmation returns to the same catalog page. --}}
                @foreach ($filterState as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <button type="submit" class="btn btn-primary">Выбрать</button>
            </form>
        @endif
    </div>

    @if ($listing->photos->count() > 1)
        {{-- Скрипт только улучшает уже работающий свайп: включает точки со стрелками,
             оживляет счётчик и убирает текстовую подсказку. Страница открывается из
             WhatsApp и не зависит от Vite-сборки, поэтому скрипт свой, как у выпадающего
             списка мест. --}}
        <script>
            (function () {
                var root = document.getElementById('listing-gallery');
                var stage = root ? root.querySelector('.gallery-stage') : null;
                var slides = stage ? stage.querySelectorAll('.gallery-slide') : [];

                if (!stage || slides.length < 2) {
                    return;
                }

                var total = slides.length;
                var dots = root.querySelectorAll('.gallery-dot');
                var railFill = root.querySelector('.gallery-rail-fill');
                var counter = root.querySelector('.gallery-count');
                var prev = root.querySelector('.gallery-arrow-prev');
                var next = root.querySelector('.gallery-arrow-next');
                var index = 0;
                var step = 1;
                var ticking = false;
                var resizeTimer = null;

                /** Шаг измеряется, а не вычисляется: отступы и дробные пиксели учитываются сами. */
                function measure() {
                    var measured = slides[1].offsetLeft - slides[0].offsetLeft;
                    step = measured > 0 ? measured : (stage.clientWidth || 1);
                }

                function render(i) {
                    index = i;

                    if (counter) {
                        counter.textContent = (i + 1) + ' / ' + total;
                    }

                    for (var d = 0; d < dots.length; d++) {
                        dots[d].classList.toggle('is-active', d === i);
                        if (d === i) {
                            dots[d].setAttribute('aria-current', 'true');
                        } else {
                            dots[d].removeAttribute('aria-current');
                        }
                    }

                    if (railFill) {
                        railFill.style.width = ((i + 1) / total * 100) + '%';
                    }
                    if (prev) {
                        prev.disabled = i === 0;
                    }
                    if (next) {
                        next.disabled = i === total - 1;
                    }
                }

                function clamp(i) {
                    return i < 0 ? 0 : (i > total - 1 ? total - 1 : i);
                }

                function sync() {
                    var i = clamp(Math.round(stage.scrollLeft / step));

                    if (i !== index) {
                        render(i);
                    }
                }

                function goTo(i) {
                    var target = clamp(i);

                    stage.scrollLeft = target * step;
                    render(target);
                }

                stage.addEventListener('scroll', function () {
                    if (ticking) {
                        return;
                    }
                    ticking = true;
                    window.requestAnimationFrame(function () {
                        ticking = false;
                        sync();
                    });
                }, { passive: true });

                for (var k = 0; k < dots.length; k++) {
                    dots[k].addEventListener('click', function (event) {
                        goTo(parseInt(event.currentTarget.getAttribute('data-index'), 10) || 0);
                    });
                }
                if (prev) {
                    prev.addEventListener('click', function () { goTo(index - 1); });
                }
                if (next) {
                    next.addEventListener('click', function () { goTo(index + 1); });
                }

                root.addEventListener('keydown', function (event) {
                    if (event.key === 'ArrowRight') {
                        event.preventDefault();
                        goTo(index + 1);
                    } else if (event.key === 'ArrowLeft') {
                        event.preventDefault();
                        goTo(index - 1);
                    }
                });

                function reanchor() {
                    measure();
                    stage.scrollLeft = index * step;
                }

                window.addEventListener('resize', function () {
                    window.clearTimeout(resizeTimer);
                    resizeTimer = window.setTimeout(reanchor, 150);
                });
                window.addEventListener('orientationchange', function () {
                    window.setTimeout(reanchor, 300);
                });
                // Возврат по кнопке «назад» отдаёт страницу из кеша прокрученной — счётчик и точки иначе врут.
                window.addEventListener('pageshow', function () {
                    measure();
                    sync();
                });

                root.classList.add('is-enhanced');
                measure();
                render(0);
            }());
        </script>
    @endif
</x-customer.layout>
