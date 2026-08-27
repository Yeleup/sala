<?php

namespace App\Support;

/**
 * Why an outbound WhatsApp message never reached the person, in words the
 * operator can act on. Meta's verdict lands in the journal as one flat
 * English string — «meta error 131026: Message undeliverable — Message
 * Undeliverable.» — and from it alone the operator cannot tell which of
 * three answers applies: call the supplier, because the number is dead;
 * fetch the administrator, because the whole account is stuck and nothing
 * is going out to anyone; or do nothing, because Meta will pass.
 *
 * The stored string is never rewritten — this is a reading of it, built at
 * display time, and a string that carries no code we know is shown exactly
 * as it came rather than replaced by a guess.
 */
class MetaDeliveryError
{
    /**
     * The shape every Meta rejection is forwarded in. Anchored to the head
     * of the string on purpose: the text that follows carries other digit
     * runs — the billing failure embeds a URL with
     * business_id=1026024386580716 — and a loose search would happily read
     * an error code out of the middle of one.
     */
    private const string CODE_PATTERN = '/^\s*meta error\s+(\d{3,6})\b/iu';

    /**
     * Meta's delivery errors in the operator's language, one short cause
     * each: the line is rendered right after «Не доставлено:» in a chat
     * bubble, so it continues that sentence instead of restating it.
     *
     * Grouped by what the reader is supposed to do about it — the recipient
     * ones are the only ones an operator can act on alone; the account ones
     * mean every send is dead until an administrator fixes Meta's settings;
     * the passing ones resolve themselves.
     *
     * @var array<int, string>
     */
    private const array CAUSES = [
        // The recipient: the operator can act.
        131026 => 'на этом номере нет рабочего WhatsApp — свяжитесь звонком и проверьте номер',
        131021 => 'сообщение адресовано на наш же собственный номер — проверьте телефон в карточке',
        131009 => 'Meta не приняла значение в запросе — чаще всего неверно записан номер',
        130403 => 'наш бизнес-аккаунт заблокировал этого человека в WhatsApp',
        131050 => 'человек отписался от маркетинговых сообщений компании',
        130472 => 'номер в контрольной группе эксперимента Meta — маркетинг ему не доставляют',
        131049 => 'сработал лимит Meta на маркетинговые сообщения одному человеку',
        131056 => 'слишком часто пишем этому номеру — Meta притормозила отправку',

        // The account: nothing is going out to anyone until it is fixed.
        131042 => 'не настроен биллинг WhatsApp Business — платные шаблоны не уходят ни к кому',
        131031 => 'аккаунт WhatsApp Business ограничен Meta — отправка не работает ни к кому',
        131048 => 'Meta ограничила отправку из-за качества нашего номера',
        131064 => 'достигнут лимит сообщений аккаунта из-за нарушений в категориях шаблонов',
        130497 => 'нашему аккаунту запрещено писать в страну этого номера',

        // The template.
        132000 => 'в шаблон передано не столько переменных, сколько в нём объявлено',
        132001 => 'такого шаблона нет в Meta или он ещё не утверждён',
        132007 => 'содержимое шаблона нарушает политику WhatsApp',
        132012 => 'значения переменных не в том формате, который задан в шаблоне',
        132015 => 'шаблон приостановлен Meta из-за низкого качества',
        132016 => 'шаблон отключён Meta навсегда после нескольких приостановок',

        // Ours to prevent, not the operator's: the send should have been a template.
        131047 => 'прошло больше 24 часов с последнего сообщения контакта — нужен был шаблон',

        // Passing on its own.
        131000 => 'сбой на стороне Meta без объяснения причины',
        131016 => 'сервис Meta временно недоступен',
        131057 => 'аккаунт WhatsApp Business на обслуживании у Meta',
        130429 => 'превышена пропускная способность отправки — Meta придержала сообщение',
    ];

    /**
     * Meta sends a reason with a rejection, but not always — and a failure
     * with no text at all used to render as a bare red tick with nothing
     * beside it, which reads like a display bug rather than an answer.
     */
    private const string UNKNOWN_CAUSE = 'Meta не назвала причину';

    /**
     * Codes that condemn the whole account rather than one recipient:
     * while one of them stands, sends are dying to everyone, so monitoring
     * must not wait for these failures to accumulate a share — a single
     * one is already the incident (July 2026: 527 of 528 templates were
     * killed by 131042 before anyone looked at a counter).
     *
     * @var list<int>
     */
    private const array ACCOUNT_LEVEL_CODES = [131042, 131048, 131064, 130429];

    public static function explain(?string $reason): string
    {
        if ($reason === null || trim($reason) === '') {
            return self::UNKNOWN_CAUSE;
        }

        $code = self::code($reason);

        if ($code === null) {
            return $reason;
        }

        return self::CAUSES[$code] ?? $reason;
    }

    /**
     * The account-level code carried by the reason, or null when the reason
     * is absent, unrecognised, or blames the recipient rather than the
     * account. Reuses the same guarded extraction as explain(), so a code
     * never gets read out of the middle of a phone number or business_id.
     */
    public static function accountLevelCode(?string $reason): ?int
    {
        if ($reason === null) {
            return null;
        }

        $code = self::code($reason);

        return in_array($code, self::ACCOUNT_LEVEL_CODES, true) ? $code : null;
    }

    /**
     * The code Meta rejected by. Besides the forwarded shape the journal
     * also holds transport errors from a send that never got accepted — an
     * HTTP exception whose message carries Meta's response body — so a
     * known code is looked for anywhere in the text as a fallback. The
     * digit boundaries are what keep that honest: a code has to stand on
     * its own, not sit inside a longer number such as a phone («131000» is
     * a substring of 77013100055) or a timestamp.
     */
    private static function code(string $reason): ?int
    {
        if (preg_match(self::CODE_PATTERN, $reason, $matches) === 1) {
            return (int) $matches[1];
        }

        foreach (array_keys(self::CAUSES) as $code) {
            if (preg_match('/(?<!\d)'.$code.'(?!\d)/', $reason) === 1) {
                return $code;
            }
        }

        return null;
    }
}
