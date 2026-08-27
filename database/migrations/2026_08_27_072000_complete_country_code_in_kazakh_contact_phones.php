<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Куда смотрит контакт. Всё это надо перевесить до удаления дубля:
     * ссылки стоят на каскадном удалении, и дубль унёс бы переписку с
     * собой.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array CONTACT_REFERENCES = [
        ['ai_operations', 'contact_id'],
        ['channel_messages', 'contact_id'],
        ['customer_requests', 'contact_id'],
        ['customer_requests', 'supplier_contact_id'],
        ['listing_renewal_batches', 'contact_id'],
        ['listings', 'contact_id'],
        ['scenario_runs', 'contact_id'],
    ];

    /**
     * Дописывает код страны контактам, заведённым казахстанским номером
     * без него, и сливает дубли, которые из-за этого развелись.
     *
     * Номер — единственная опознавательная метка контакта, а WhatsApp
     * присылает его в международном формате («77013362215»). Номер,
     * записанный как «7013362215», с приходящим не совпадает, поэтому
     * первый же ответ поставщика заводил второй контакт: исходящая
     * история (в том числе отправленный шаблон) оставалась на старом
     * контакте, входящий ответ ложился на новый, а нажатие кнопки
     * сценария молча пропадало — запуск закреплён за контактом, и ответ
     * «от чужого» отбрасывается. Со стороны это выглядело как поставщик,
     * подтвердивший актуальность и всё равно уехавший в архив.
     *
     * Форму админки от таких номеров закрыли проверкой длины, но контакты,
     * заведённые до неё, остались — и продолжают разъезжаться на каждом
     * входящем.
     */
    public function up(): void
    {
        $this->contactsWithoutCountryCode()->each(function (object $contact): void {
            $canonical = '7'.$contact->phone;
            $duplicate = DB::table('contacts')->where('phone', $canonical)->first();

            if ($duplicate !== null) {
                $this->mergeIntoOriginal($contact, $duplicate);
            }

            DB::table('contacts')->where('id', $contact->id)->update(['phone' => $canonical]);
        });
    }

    public function down(): void
    {
        // Разделить слитые контакты обратно нечем, да и незачем: разрыв
        // диалога надвое был дефектом, а не состоянием, к которому
        // возвращаются.
    }

    /**
     * Казахстанский номер, записанный без кода страны: код оператора,
     * начинающийся с семёрки, и семь цифр. Длина отсеивает и иностранные
     * номера, и недобранные — их трогать нельзя, дописанный им код
     * страны сделал бы из них чужого абонента.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function contactsWithoutCountryCode(): \Illuminate\Support\Collection
    {
        return DB::table('contacts')
            ->where('phone', 'like', '7%')
            ->whereRaw('length(phone) = 10')
            ->orderBy('id')
            ->get(['id', 'phone', 'profile_name', 'display_name', 'last_inbound_at'])
            ->filter(fn (object $contact): bool => preg_match('/^7\d{9}$/', (string) $contact->phone) === 1);
    }

    /**
     * Выживает исходный контакт: на нём объявления, запуски сценариев и
     * имя, выбранное оператором. Дубль отдаёт ему свою переписку, имя
     * профиля WhatsApp и время последнего входящего — по нему считается
     * 24-часовое окно, и потерять его значит лишить бота права ответить
     * бесплатным сообщением.
     */
    private function mergeIntoOriginal(object $original, object $duplicate): void
    {
        foreach (self::CONTACT_REFERENCES as [$table, $column]) {
            DB::table($table)->where($column, $duplicate->id)->update([$column => $original->id]);
        }

        $this->keepLivelierSession($original, $duplicate);

        DB::table('contacts')->where('id', $original->id)->update([
            'profile_name' => $original->profile_name ?? $duplicate->profile_name,
            'display_name' => $original->display_name ?? $duplicate->display_name,
            'last_inbound_at' => $this->later($original->last_inbound_at, $duplicate->last_inbound_at),
        ]);

        DB::table('contacts')->where('id', $duplicate->id)->delete();
    }

    /**
     * Диалог у контакта один — на contact_id стоит уникальный индекс, —
     * поэтому из двух остаётся тот, что обновлялся позже: в нём шаг, на
     * котором человек стоит сейчас. Проигравший уходит: диалог дубля —
     * вместе с самим дублём по каскаду.
     */
    private function keepLivelierSession(object $original, object $duplicate): void
    {
        $theirs = DB::table('bot_sessions')->where('contact_id', $duplicate->id)->first();

        if ($theirs === null) {
            return;
        }

        $ours = DB::table('bot_sessions')->where('contact_id', $original->id)->first();

        if ($ours !== null) {
            if ($this->later($ours->updated_at, $theirs->updated_at) === $ours->updated_at) {
                return;
            }

            DB::table('bot_sessions')->where('id', $ours->id)->delete();
        }

        DB::table('bot_sessions')->where('id', $theirs->id)->update(['contact_id' => $original->id]);
    }

    /**
     * Позднее из двух временных значений, любое из которых может
     * отсутствовать.
     */
    private function later(?string $first, ?string $second): ?string
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return strtotime($first) >= strtotime($second) ? $first : $second;
    }
};
