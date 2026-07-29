/**
 * Сценарий демо: рабочий день оператора в админке — от входа до
 * публикации объявления, заведённого за клиента по телефону, и разбора
 * очереди модерации.
 *
 * Сцена — это текст закадрового голоса плюс действия в браузере. Текст
 * озвучивается заранее, поэтому длительность сцены известна до записи:
 * запись держит кадр ровно столько, сколько звучит голос.
 *
 * Демо намеренно не подтверждает «Одобрить»/«Отклонить»: эти действия
 * шлют поставщику реальное сообщение в WhatsApp. Очередь модерации
 * показывается до подтверждения, а публикуется объявление действием
 * «Опубликовать» — оно уведомлений не отправляет по определению.
 */
import { BASE_URL, CREDENTIALS } from './config.mjs';
import { button, click, field, highlight, hover, select, settle, tab, type } from './actions.mjs';

/**
 * Девять цифр, которые оператор набирает поверх шаблона: код оператора
 * «01» (номер получается вида +7(701)…) и уникальный хвост, чтобы в
 * каждом прогоне заводился новый поставщик, а не всплывал дубль.
 */
const PHONE_INPUT = `01${String(Date.now()).slice(-7)}`;
const LISTING_TITLE = 'Аренда автокрана 25 т';

export const scenes = [
    {
        id: 'intro',
        narration:
            'Это рабочее место оператора маркетплейса спецтехники. Сюда приходят заявки от поставщиков — ' +
            'часть собирает бот в WhatsApp, часть оператор заводит сам, когда клиент звонит по телефону. ' +
            'Разберём весь путь: от входа в панель до опубликованного объявления. Начинаем со входа.',
        async run(page) {
            await page.goto(`${BASE_URL}/admin/login`);
            await settle(page);
            await type(page, page.locator('input[type="email"]').first(), CREDENTIALS.email, { delay: 40 });
            await type(page, page.locator('input[type="password"]').first(), CREDENTIALS.password, { delay: 40 });
            await click(page, page.getByRole('button', { name: /Sign in|Войти/i }).first());
            await settle(page, 1200);
        },
    },
    {
        id: 'listing-list',
        narration:
            'Список объявлений открывается на вкладке «Все» — и это важно. Объявление, которое оператор ' +
            'только что завёл за клиента, видно сразу, на первом экране, без снятия фильтров. ' +
            'Рядом две рабочие подборки: «На модерации» со счётчиком тех, кто ждёт вердикта, — ' +
            'при пустой очереди счётчика просто нет, — и «Черновики» с недозаполненными объявлениями. ' +
            'Порядок везде один: новые сверху. Остальные статусы — опубликованные, отклонённые, архив — ' +
            'достаются фильтром по статусу.',
        async run(page) {
            await page.goto(`${BASE_URL}/admin/marketplace/listings`);
            await settle(page, 1200);
            await highlight(page, page.locator('.fi-tabs').first(), { ms: 2200 });
            await hover(page, tab(page, 'На модерации'), { linger: 1200 });
            await hover(page, tab(page, 'Черновики'), { linger: 1000 });
            await click(page, page.locator('button[aria-label="Filter"]').first(), { settle: 1200 });
            await page.keyboard.press('Escape');
            await settle(page, 400);
        },
    },
    {
        id: 'create-open',
        narration:
            'Звонит клиент: у него есть автокран, он хочет сдавать его в аренду. Нажимаем «Новое объявление». ' +
            'Форма устроена под разговор по телефону: сверху подсказка «Готовность к публикации» — ' +
            'она перечисляет, чего ещё не хватает, и по сути работает как скрипт вопросов клиенту. ' +
            'Обязательных полей всего два — поставщик и тип. Остальное можно дозаполнить позже: ' +
            'недозаполненное объявление сохранится черновиком и не потеряется.',
        async run(page) {
            await click(page, button(page, 'Новое объявление'), { settle: 400 });
            await settle(page, 1200);
            await highlight(page, page.getByText('Не хватает для публикации').first(), { ms: 2500 });
        },
    },
    {
        id: 'supplier',
        narration:
            'Первое поле — поставщик. Клиент звонит впервые, поэтому заводим его прямо отсюда, ' +
            'не выходя из формы и не теряя набранное. В окне сначала можно проверить, ' +
            'не звонил ли он раньше: поиск находит контакт и по имени, и по номеру в любом написании. ' +
            'Если совпадений нет — заполняем номер. Телефон набирается по шаблону: ' +
            'код страны и первая семёрка уже подставлены, поэтому диктуемую «восьмёрку» набирать не нужно, ' +
            'и вопрос «восемь или семь» на линии не возникает. Оператор вводит только девять цифр. ' +
            'Номер короче одиннадцати цифр система не примет — недобранный номер отклоняется сразу.',
        async run(page) {
            // Кнопка «+» рядом с полем: она заводит поставщика, не выходя из формы.
            const create = page.locator('.fi-fo-field')
                .filter({ hasText: 'Поставщик' })
                .last()
                .locator('button[aria-label="Create"]')
                .first();

            await click(page, create, { settle: 500 });
            await settle(page, 1200);

            const modal = page.locator('.fi-modal-window').last();

            await highlight(page, modal.getByText('Уже заведён?').first(), { ms: 1800 });
            await type(page, field(modal, 'phone'), PHONE_INPUT, { delay: 90 });
            await settle(page, 900);
            await type(page, field(modal, 'display_name'), 'Ерлан, автокраны', { delay: 45 });
            await click(page, button(modal, 'Готово'), { settle: 900 });
            await settle(page, 1500);
        },
    },
    {
        id: 'listing-fields',
        narration:
            'Дальше — сам предмет разговора. Тип: техника или услуга. Тип задаёт список категорий, ' +
            'поэтому категорию выбираем после него; марка есть только у техники. ' +
            'Название, описание, локация, уточнение адреса, цена — всё это оператор набирает со слов клиента, ' +
            'пока тот на линии. Локация ищется по справочнику: город, район или село, ' +
            'а уточнение адреса дописывается свободным текстом. ' +
            'Обратите внимание на подсказку сверху: она пересчитывается на ходу и подсказывает, ' +
            'что ещё спросить, пока клиент не положил трубку.',
        async run(page) {
            await select(page, page, 'type', 'Техника');
            await settle(page, 800);
            await type(page, field(page, 'title'), LISTING_TITLE, { delay: 40 });
            await select(page, page, 'category_id', 'Автокраны', { search: 'Автокран' });
            await select(page, page, 'brand_id', 'Caterpillar', { search: 'Cater' });
            await type(
                page,
                field(page, 'description'),
                'Автокран 25 тонн, стрела 28 метров. Работаем по городу и области, есть выезд на объект.',
                { delay: 18 },
            );
            await select(page, page, 'location_id', 'Караганда', { search: 'Караганд' });
            await type(page, field(page, 'location_detail'), 'центр, выезд по области', { delay: 35 });
            await type(page, field(page, 'price'), '25000 тг/ч', { delay: 50 });
            await settle(page, 600);
            await highlight(page, page.getByText(/Не хватает для публикации|Все поля заполнены/).first(), { ms: 2000 });
        },
    },
    {
        id: 'save',
        narration:
            'Сохраняем. Объявление создано и легло черновиком — статус меняется не в форме, ' +
            'а отдельными действиями, чтобы жизненный цикл нельзя было сломать случайной правкой поля.',
        async run(page) {
            await click(page, button(page, /Create|Создать/), { settle: 800 });
            await settle(page, 2000);
        },
    },
    {
        id: 'publish',
        narration:
            'Объявление, которое оператор набрал сам, через очередь модерации не идёт: ' +
            'он и автор текста, и вердикт по нему одновременно, так что лишние клики ничего не проверяют. ' +
            'Вместо очереди работают два ограничителя — полнота полей и записанный автор публикации. ' +
            'Кнопка «Опубликовать» неактивна, пока чего-то не хватает, и подсказка говорит, чего именно. ' +
            'Публикуем: объявление попадает в поиск на тридцать дней. Поставщику при этом ничего не отправляется — ' +
            'объявление с ним и так согласовано по телефону.',
        async run(page) {
            const publish = button(page, 'Опубликовать');
            await hover(page, publish, { linger: 1500 });
            await click(page, publish, { settle: 700 });
            await settle(page, 900);

            const modal = page.locator('.fi-modal-window').last();
            await highlight(page, modal, { ms: 2500 });
            await click(page, modal.getByRole('button', { name: /Опубликовать|Confirm/ }).last(), { settle: 900 });
            await settle(page, 2000);
        },
    },
    {
        id: 'moderation',
        narration:
            'Вторая половина работы оператора — очередь модерации. Сюда попадают объявления, ' +
            'которые поставщики собрали сами в переписке с ботом: их текст оператор видит впервые, ' +
            'поэтому вердикт нужен. Вкладка «На модерации» показывает, сколько объявлений ждут. ' +
            'Открываем любое: та же форма, но она читается как экран проверки — статус, ' +
            'собранные фотографии, транскрипции голосовых, которые прислал поставщик. ' +
            'Решений два. «Одобрить» — объявление уходит в поиск, поставщику приходит уведомление в WhatsApp. ' +
            '«Отклонить» — причина обязательна: поставщик увидит её по ссылке и сможет исправить и прислать снова.',
        async run(page) {
            await page.goto(`${BASE_URL}/admin/marketplace/listings`);
            await settle(page, 1000);
            await click(page, tab(page, 'На модерации'), { settle: 1200 });
            await settle(page, 1200);

            const firstRow = page.locator('.fi-ta-row').first();
            await click(page, firstRow.locator('a.fi-ta-col').first(), { settle: 800 });
            await settle(page, 1800);
            await hover(page, button(page, 'Одобрить'), { linger: 1200 });
            await hover(page, button(page, 'Отклонить'), { linger: 1200 });
            await click(page, button(page, 'Отклонить'), { settle: 800 });
            await settle(page, 900);

            const modal = page.locator('.fi-modal-window').last();
            await highlight(page, modal.getByText('Причина отклонения').first(), { ms: 2000 });
            await click(page, modal.getByRole('button', { name: /Cancel|Отмена/i }).first(), { settle: 700 });
            await settle(page, 700);
        },
    },
    {
        id: 'preview',
        narration:
            'И последнее. Проверять объявление по полям формы — значит не видеть того, что реально попадёт в каталог: ' +
            'как обрежутся фотографии, где переносится описание, читается ли заголовок как предложение. ' +
            'Кнопка «Посмотреть объявление» открывает его ровно так, как увидит заказчик. ' +
            'Отсюда решение принимается за секунды — и объявление уходит в поиск.',
        async run(page) {
            const preview = button(page, 'Посмотреть объявление');
            await hover(page, preview, { linger: 1500 });

            const url = await preview.getAttribute('href');
            await page.goto(url.startsWith('http') ? url : `${BASE_URL}${url}`);
            await settle(page, 1500);
            await page.mouse.wheel(0, 400);
            await page.waitForTimeout(1200);
            await page.mouse.wheel(0, 400);
            await page.waitForTimeout(1200);
        },
    },
    {
        id: 'outro',
        narration:
            'Итого: объявление за клиента заводится за один звонок, черновик не теряется, ' +
            'очередь модерации разбирается вкладкой в один клик, а перед вердиктом объявление видно глазами заказчика. ' +
            'Это и есть весь рабочий цикл оператора.',
        async run(page) {
            await page.goto(`${BASE_URL}/admin/marketplace/listings`);
            await settle(page, 2000);
        },
    },
];
