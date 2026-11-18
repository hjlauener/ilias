<?php
// Testdaten für die Test-ILIAS (dev/test-ilias): Deutsch, Testbenutzer, Kurs
// "Testkurs" mit Dozentin und 12 Studierenden. Nur für Testinstallationen!
// Aufruf: siehe dev/test-ilias/README.md
chdir('/var/www/html');
require_once './vendor/composer/vendor/autoload.php';

function say(string $m): void { fwrite(STDERR, $m . "\n"); }
$startup = new ILIAS\Cron\CLI\StartUp('default', 'root');
$startup->authenticate();
global $DIC;
try {

// --- German language
$installed = ilObjLanguage::getInstalledLanguages();
$has_de = false;
foreach ($installed as $l) { if ($l->getKey() === 'de') { $has_de = true; } }
if (!$has_de) {
    $set = $DIC->database()->query("SELECT obj_id FROM object_data WHERE type = 'lng' AND title = 'de'");
    $rec = $DIC->database()->fetchAssoc($set);
    $lng_obj = new ilObjLanguage((int) $rec['obj_id']);
    $lng_obj->install();
    say("German installed");
}

// --- test installation: no password change on first login
$DIC->settings()->set('ps_password_change_on_first_login_enabled', '0');

// --- users
$people = [
    ['dozent', 'Doris', 'Dozentin'],
    ['anna', 'Anna', 'Aebi'], ['ben', 'Ben', 'Brunner'], ['carla', 'Carla', 'Caduff'],
    ['dario', 'Dario', 'Derungs'], ['eva', 'Eva', 'Egli'], ['fabio', 'Fabio', 'Frei'],
    ['gina', 'Gina', 'Gerber'], ['hugo', 'Hugo', 'Huber'], ['ines', 'Ines', 'Imhof'],
    ['jan', 'Jan', 'Joos'], ['kim', 'Kim', 'Keller'], ['lea', 'Lea', 'Lüthi'],
];
$ids = [];
foreach ($people as [$login, $first, $last]) {
    $id = (int) ilObjUser::_lookupId($login);
    if ($id === 0) {
        $u = new ilObjUser();
        $u->setLogin($login);
        $u->setFirstname($first);
        $u->setLastname($last);
        $u->setEmail($login . '@example.com');
        $u->setPasswd('Test1234!', ilObjUser::PASSWD_PLAIN);
        $u->setActive(true);
        $u->setTimeLimitUnlimited(true);
        $u->setAgreeDate(date('Y-m-d H:i:s'));
        $u->create();
        $u->saveAsNew();
        $DIC->rbac()->admin()->assignUser(4, $u->getId()); // global role "User"
        $id = $u->getId();
    }
    $u = new ilObjUser($id);
    $u->setLanguage('de');
    $u->setPref('language', 'de');
    $u->writePrefs();
    $u->update();
    $ids[$login] = $id;
}
$root = new ilObjUser(6);
$root->setLanguage('de');
$root->setPref('language', 'de');
$root->writePrefs();
say("users: " . json_encode($ids));

// --- course
$set = $DIC->database()->query("SELECT r.ref_id FROM object_data o JOIN object_reference r ON r.obj_id = o.obj_id WHERE o.type = 'crs' AND o.title = 'Testkurs' AND r.deleted IS NULL");
$rec = $DIC->database()->fetchAssoc($set);
if ($rec) {
    $crs = new ilObjCourse((int) $rec['ref_id']);
} else {
    $crs = new ilObjCourse();
    $crs->setTitle('Testkurs');
    $crs->create();
    $crs->createReference();
    $crs->putInTree(1);
    $crs->setPermissions(1);
}
$crs->setOfflineStatus(false);
$crs->update();

$part = ilCourseParticipants::_getInstanceByObjId($crs->getId());
if (!$part->isAdmin($ids['dozent'])) {
    $part->add($ids['dozent'], ilParticipants::IL_CRS_ADMIN);
}
foreach ($ids as $login => $id) {
    if ($login !== 'dozent' && !$part->isMember($id)) {
        $part->add($id, ilParticipants::IL_CRS_MEMBER);
    }
}
say("course ref_id: " . $crs->getRefId());
} catch (Throwable $e) {
    say(get_class($e) . ": " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
    say($e->getTraceAsString());
}
$startup->logout();
