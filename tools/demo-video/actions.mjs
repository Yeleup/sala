/**
 * Действия «как человек»: видимое движение курсора, набор с задержкой,
 * подсветка элемента. Запись Playwright не содержит курсора и не
 * показывает, куда смотрит зритель, — поэтому клики идут через
 * page.mouse (курсор рисует cursor-overlay.js), а важные места
 * подсвечиваются рамкой.
 */

const CLICK_SETTLE_MS = 350;

/** Плавно ведёт курсор к центру элемента, чтобы движение было видно в кадре. */
async function moveTo(page, locator) {
    await locator.scrollIntoViewIfNeeded();
    const box = await locator.boundingBox();

    if (box === null) {
        throw new Error('Элемент не виден в кадре — курсор вести некуда.');
    }

    const x = box.x + box.width / 2;
    const y = box.y + box.height / 2;

    await page.mouse.move(x, y, { steps: 25 });

    return { x, y };
}

export async function click(page, locator, { settle = CLICK_SETTLE_MS } = {}) {
    await moveTo(page, locator);
    await page.mouse.down();
    await page.mouse.up();
    await page.waitForTimeout(settle);
}

export async function hover(page, locator, { linger = 500 } = {}) {
    await moveTo(page, locator);
    await page.waitForTimeout(linger);
}

/** Набор посимвольно: в кадре видно, как заполняется поле. */
export async function type(page, locator, text, { delay = 55, clear = false } = {}) {
    await click(page, locator, { settle: 120 });

    if (clear) {
        await locator.fill('');
    }

    await locator.pressSequentially(text, { delay });
    await page.waitForTimeout(200);
}

/** Рамка вокруг элемента на заданное время — «смотрите сюда». */
export async function highlight(page, locator, { ms = 1500 } = {}) {
    await locator.scrollIntoViewIfNeeded();
    const handle = await locator.elementHandle();

    if (handle === null) {
        return;
    }

    await page.evaluate(
        ([element, duration]) => window.__demoHighlight(element, duration),
        [handle, ms],
    );
    await page.waitForTimeout(ms);
}

/** Ждёт, пока Livewire/Filament перестанут перерисовывать экран. */
export async function settle(page, ms = 700) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(ms);
}

/**
 * Поле формы по имени состояния, а не по подписи: на странице ресурса
 * id выглядит как `form.title`, в модальном окне — как
 * `mountedActionSchema0.phone`, поэтому опознаём по хвосту. Подписи для
 * этого не годятся — «тип» встречается и в тексте подсказки готовности.
 */
export function field(scope, name) {
    return scope.locator(`[id$=".${name}"]`).first();
}

/**
 * Обёртка поля. У searchable-селекта нет элемента с id — есть только
 * подпись, которая на него ссылается, поэтому ищем блок по ней.
 */
function fieldWrapper(scope, name) {
    return scope
        .locator('.fi-fo-field')
        .filter({ has: scope.locator(`label[for$=".${name}"]`) })
        .first();
}

/**
 * Выбор значения в Filament-селекте. Обычный селект рендерится нативным
 * <select>, searchable — своим выпадающим списком с полем поиска;
 * помощник разбирается сам, чтобы сценарий не зависел от того, как поле
 * сконфигурировано в форме.
 */
export async function select(page, scope, name, option, { search = null } = {}) {
    const wrapper = fieldWrapper(scope, name);
    const native = wrapper.locator('select').first();

    if (await native.count() > 0 && await native.isVisible().catch(() => false)) {
        await click(page, native, { settle: 150 });
        await native.selectOption({ label: option });
        await page.waitForTimeout(500);

        return;
    }

    await click(page, wrapper.locator('.fi-select-input-btn').first(), { settle: 400 });

    if (search !== null) {
        // У каждого селекта свой скрытый поиск — берём тот, что открыт.
        const box = page.locator('.fi-select-input-search-ctn input:visible').first();
        await box.waitFor({ state: 'visible', timeout: 10000 });
        await box.pressSequentially(search, { delay: 70 });
        await page.waitForTimeout(1500);
    }

    const item = page
        .locator('.fi-select-input-option:visible')
        .filter({ hasText: option })
        .first();

    await item.waitFor({ state: 'visible', timeout: 15000 });
    await click(page, item, { settle: 500 });
}

/**
 * Кнопка Filament по её подписи. Действия рендерятся то кнопкой, то
 * ссылкой (переход на страницу создания, «Посмотреть объявление»),
 * поэтому ищем и то, и другое.
 */
export function button(scope, label) {
    return scope
        .locator('button:visible, a:visible')
        .filter({ hasText: label })
        .first();
}

/** Вкладка списка («Все», «На модерации», «Черновики»). */
export function tab(scope, label) {
    return scope
        .locator('.fi-tabs a, .fi-tabs button')
        .filter({ hasText: label })
        .first();
}
