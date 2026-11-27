<?php

declare(strict_types=1);

/**
 * Swap market (methods b and f): tells a person by ILIAS mail that his/her
 * swap request has been fulfilled by somebody else (the places have been
 * swapped). The mail is sent by the system and written in the language of
 * the recipient. Depending on the personal mail settings of the recipient,
 * ILIAS also forwards it to his/her e-mail address.
 */
class ilGrmSwapNotification
{
    private string $lang_prefix;

    /**
     * @param string $lang_prefix language prefix of the plugin ("rep_robj_xgrm")
     */
    public function __construct(string $lang_prefix)
    {
        $this->lang_prefix = $lang_prefix;
    }

    /**
     * @param int    $recipient_id    person whose swap request has been fulfilled
     * @param int    $partner_id      person who took over his/her place
     * @param string $new_group_title group the recipient is in now
     * @return bool false if the mail could not be handed over to ILIAS
     */
    public function sendSwapped(
        int $recipient_id,
        int $partner_id,
        string $new_group_title,
        string $management_title,
        string $object_title,
        int $ref_id
    ): bool {
        $login = ilObjUser::_lookupLogin($recipient_id);
        if ($login === "") {
            return false;
        }

        $lng = ilLanguageFactory::_getLanguageOfUser($recipient_id);
        $lng->loadLanguageModule($this->lang_prefix);
        $txt = fn(string $key): string => $lng->txt($this->lang_prefix . "_" . $key, $this->lang_prefix);

        $body = sprintf($txt("swap_mail_salutation"), ilObjUser::_lookupFullname($recipient_id)) . "\n\n"
            . sprintf($txt("swap_mail_body"), ilObjUser::_lookupFullname($partner_id), $new_group_title) . "\n\n"
            . sprintf($txt("swap_mail_management"), $management_title, $object_title) . "\n"
            . ilLink::_getStaticLink($ref_id, "xgrm");

        $mail = new ilMail(ANONYMOUS_USER_ID);
        return $mail->enqueue($login, "", "", $txt("swap_mail_subject"), $body, []) === [];
    }
}
