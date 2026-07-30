{{-- Filament поставляет предкомпилированный CSS без произвольных
     tailwind-классов кастомных вьюх, поэтому вторая полоса прокрутки описана
     локальными стилями. Полоса-дублёр пуста: её ширину задаёт распорка внутри,
     а положение синхронизировано с настоящим скроллером таблицы. --}}
<style>
    .fi-lts {
        overflow-x: auto;
        overflow-y: hidden;
        margin-bottom: 0.25rem;
        scrollbar-width: thin;
        scrollbar-color: rgb(156 163 175 / 0.7) transparent;
    }

    .fi-lts > div {
        height: 1px;
    }

    {{-- В macOS полосы прокрутки по умолчанию всплывающие и в пустом блоке
         не показались бы вовсе, поэтому толщина и цвет заданы явно. --}}
    .fi-lts::-webkit-scrollbar {
        height: 0.625rem;
    }

    .fi-lts::-webkit-scrollbar-track {
        background: transparent;
    }

    .fi-lts::-webkit-scrollbar-thumb {
        background: rgb(156 163 175 / 0.7);
        border-radius: 9999px;
    }

    .fi-lts::-webkit-scrollbar-thumb:hover {
        background: rgb(107 114 128 / 0.9);
    }

    .dark .fi-lts {
        scrollbar-color: rgb(107 114 128 / 0.9) transparent;
    }

    .dark .fi-lts::-webkit-scrollbar-thumb {
        background: rgb(107 114 128 / 0.9);
    }

    .dark .fi-lts::-webkit-scrollbar-thumb:hover {
        background: rgb(156 163 175 / 1);
    }
</style>

<script>
    (() => {
        if (window.filamentTableTopScrollbar) {
            return
        }

        window.filamentTableTopScrollbar = true

        const CLASS = 'fi-lts'

        let pending = false
        let measured = ''

        // Один пересчёт на пачку: морфинг Livewire и всплывающие меню сыплют
        // мутациями подряд, а мерить нужно по осевшей разметке. Таймер, а не
        // requestAnimationFrame: тот молчит, пока вкладка не отрисовывается, и
        // полоса не появилась бы до первого взгляда на неё.
        const schedule = () => {
            if (pending) {
                return
            }

            pending = true

            setTimeout(() => {
                pending = false
                sync()
            })
        }

        // Синхронизация без флагов: присвоение равного значения не порождает
        // нового события, поэтому взаимные обработчики затухают сами и не
        // теряют встречную прокрутку.
        const bind = (bar, content) => {
            const mirror = (from, to) => () => {
                if (to.scrollLeft !== from.scrollLeft) {
                    to.scrollLeft = from.scrollLeft
                }
            }

            bar.addEventListener('scroll', mirror(bar, content), { passive: true })
            content.addEventListener('scroll', mirror(content, bar), { passive: true })
        }

        // Ширина таблицы меняется и без правок разметки — когда дозагрузился
        // шрифт или в ячейку пришёл текст длиннее прежнего. Повторный observe
        // того же элемента ничего не стоит, поэтому подписка обновляется прямо
        // в sync и не устаревает после перерисовки.
        const resizes = new ResizeObserver(() => schedule())

        // Идемпотентна: пересоздаёт полосу, которую снёс морфинг Livewire (для
        // него это чужой элемент, которого нет в присланной разметке), и
        // пересчитывает ширину после смены страницы, фильтра или набора колонок.
        const sync = () => {
            const content = document.querySelector('.fi-ta-main > .fi-ta-content-ctn')

            if (! content) {
                return
            }

            let bar = content.previousElementSibling

            if (! bar?.classList.contains(CLASS)) {
                bar = document.createElement('div')
                bar.className = CLASS
                bar.append(document.createElement('div'))
                content.before(bar)
                bind(bar, content)
            }

            resizes.observe(content)

            const table = content.querySelector('.fi-ta-table')

            if (table) {
                resizes.observe(table)
            }

            const width = `${content.scrollWidth}px`

            if (bar.firstElementChild.style.width !== width) {
                bar.firstElementChild.style.width = width
            }

            const fits = content.scrollWidth <= content.clientWidth

            if (bar.hidden !== fits) {
                bar.hidden = fits
            }

            if (bar.scrollLeft !== content.scrollLeft) {
                bar.scrollLeft = content.scrollLeft
            }

            // ResizeObserver будит нас посреди раскладки, и первый замер после
            // смены размера окна ещё не окончательный. Пока цифры меняются от
            // прохода к проходу, делаем ещё один — на устоявшихся совпадут и
            // проход не повторится.
            const shape = `${content.scrollWidth}x${content.clientWidth}`

            if (shape !== measured) {
                measured = shape
                schedule()
            }
        }

        let mutations = null

        const watch = () => {
            sync()

            // Наблюдаем за оболочкой страницы, а не за самой таблицей: таблицу
            // Livewire заменяет целиком, и подписка на неё пережила бы ровно
            // одну перерисовку.
            const root = document.querySelector('.fi-main') ?? document.body

            mutations?.disconnect()
            mutations = new MutationObserver(schedule)
            mutations.observe(root, { childList: true, subtree: true })
        }

        document.addEventListener('livewire:navigated', watch)
        window.addEventListener('resize', schedule)
        document.fonts?.ready.then(schedule)

        watch()
    })()
</script>
