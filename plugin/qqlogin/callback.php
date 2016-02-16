<?php

/**
 * qq��¼ģ��ʵ��
 */
require_once ("../../config.php");
require_once ("../../lib/global.func.php");
require_once ("../../lib/cache.class.php");
require_once ("../../lib/db.class.php");
require_once("./API/qqConnectAPI.php");
define('TIPASK_ROOT', substr(dirname(__FILE__), 0, -15));
define(SITE_URL, 'http://' . $_SERVER['HTTP_HOST'] . substr($_SERVER['PHP_SELF'], 0, -27));
$db = new db(DB_HOST, DB_USER, DB_PW, DB_NAME, DB_CHARSET, DB_CONNECT);
$cache = new cache($db);
$setting = $cache->load('setting');
$qc = new QC();
$token = $qc->qq_callback();
$openid = $qc->get_openid();
$qc = new QC($token, $openid);
$sid = tcookie('sid');
$auth = tcookie('auth');
$user = array();
list($uid, $password) = empty($auth) ? array(0, 0) : taddslashes(explode("\t", authcode($auth,'DECODE')), 1);
$user = array();
if ($uid && $password) {
    $user = get_user($uid);
    if ($password != $user['password']) {
        $user = array();
    }
}

if (!$user) {
    $user = get_by_openid($openid);
} else {
    remove_auth($openid);
    add_auth($token, $openid, $uid);
    header("Location:" . SITE_URL . "index.php?user/mycategory");
    exit;
}
if ($user) {
    add_auth($token, $openid, $uid);
    refresh($user);
    header("Location:" . SITE_URL);
    exit;
} else {
    $userinfo = $qc->get_user_info();
    $gender = 2;
    if ($userinfo['gender'] == '��') {
        $gender = 1;
    } else if ($userinfo['gender'] == 'Ů') {
        $gender = 0;
    }
    $randpasswd = strtolower(random(6, 1));
    $uid = add_user($userinfo['nickname'], $randpasswd, $gender, $token, $openid);
    $userid = $uid;
    if ($uid && $setting['qqlogin_avatar']) {
        $avatardir = "/data/avatar/";
        $uid = sprintf("%09d", $uid);
        $dir1 = $avatardir . substr($uid, 0, 3);
        $dir2 = $dir1 . '/' . substr($uid, 3, 2);
        $dir3 = $dir2 . '/' . substr($uid, 5, 2);
        (!is_dir(TIPASK_ROOT . $dir1)) && forcemkdir(TIPASK_ROOT . $dir1);
        (!is_dir(TIPASK_ROOT . $dir2)) && forcemkdir(TIPASK_ROOT . $dir2);
        (!is_dir(TIPASK_ROOT . $dir3)) && forcemkdir(TIPASK_ROOT . $dir3);
        $smallimg = $dir3 . "/small_" . $uid . '.jpg';
        get_remote_image($userinfo['figureurl_qq_2'], TIPASK_ROOT . $smallimg);
        $user = get_user($uid);
        $redirect = url("user/profile", 1);
        $subject = "��ϲ����" . $setting['site_name'] . "ע��ɹ���";
        $content = '�������������ʺͻش���!���ĵ�¼�û����� ' . $user['username'] . ',��¼������ ' . $randpasswd . ',Ϊ�˱�֤�����˺Ű�ȫ���뼰ʱ�޸����룬���Ƹ�����Ϣ!<br /><a href="' . $redirect . '">�����˴����Ƹ�����Ϣ</a>';
        $db->query('INSERT INTO ' . DB_TABLEPRE . "message  SET `from`='" . $setting['site_name'] . "' , `fromuid`=0 , `touid`=$userid  , `subject`='$subject' , `time`=" . time() . " , `content`='$content'");
        refresh($user);
        header("Location:" . SITE_URL);
        exit;
    }
    $user = get_user($userid);
    $redirect = url("user/profile", 1);
    $subject = "��ϲ����" . $setting['site_name'] . "ע��ɹ���";
    $content = '�������������ʺͻش���!���ĵ�¼�û����� ' . $user['username'] . ',��¼������ ' . $randpasswd . ',Ϊ�˱�֤�����˺Ű�ȫ���뼰ʱ�޸����룬���Ƹ�����Ϣ!<br /><a href="' . $redirect . '">�����˴����Ƹ�����Ϣ</a>';
    $db->query('INSERT INTO ' . DB_TABLEPRE . "message  SET `from`='" . $setting['site_name'] . "' , `fromuid`=0 , `touid`=$userid  , `subject`='$subject' , `time`=" . time() . " , `content`='$content'");
    refresh($user);
    header("Location:" . SITE_URL);
}

function get_user($uid) {
    global $db;
    return $db->fetch_first("SELECT * FROM " . DB_TABLEPRE . "user WHERE uid='$uid'");
}

function get_by_openid($openid) {
    global $db;
    return $db->fetch_first("SELECT * FROM " . DB_TABLEPRE . "user AS u," . DB_TABLEPRE . "login_auth as la WHERE u.uid=la.uid AND la.openid='$openid'");
}

function get_by_username($username) {
    global $db;
    return $db->fetch_first("SELECT * FROM " . DB_TABLEPRE . "user WHERE username='$username'");
}

function get_last_username($username) {
    global $db;
    return $db->fetch_first("SELECT * FROM " . DB_TABLEPRE . "user WHERE username LIKE '$username%' ORDER BY uid DESC");
}

function add_user($username, $password, $gender, $token, $openid) {
    global $db, $setting;
    $user = get_by_username($username);
    $password = md5($password);
    if ($user) {
        $lastuser = get_last_username($username);
        $suffix = substr($lastuser['username'], strlen($username));
        $username = $username . '' . (intval($suffix) + 1);
    }
    $time = time();
    $db->query("INSERT INTO " . DB_TABLEPRE . "user(username,password,email,gender,regip,regtime,`lastlogin`) values ('$username','$password','null',$gender,'" . getip() . "',$time,$time)");
    $uid = $db->insert_id();
    $db->query("INSERT INTO " . DB_TABLEPRE . "login_auth(uid,type,token,openid,time) values ($uid,'qq','$token','$openid',$time)");
    $credit1 = $setting['credit1_register'];
    $credit2 = $setting['credit2_register'];
    $db->query("INSERT INTO " . DB_TABLEPRE . "credit(uid,time,operation,credit1,credit2) VALUES ($uid,$time,'plugin/qqlogin',$credit1,$credit2) ");
    $db->query("UPDATE " . DB_TABLEPRE . "user SET credit2=credit2+$credit1,credit1=credit1+$credit2 WHERE uid=$uid ");
    return $uid;
}

function add_auth($token, $openid, $uid) {
    global $db;
    $time = time();
    $db->query("REPLACE INTO " . DB_TABLEPRE . "login_auth(uid,type,token,openid,time) values ($uid,'qq','$token','$openid',$time)");
}

function remove_auth($openid) {
    global $db;
    $db->query("DELETE FROM " . DB_TABLEPRE . "login_auth WHERE openid='$openid'");
}

function refresh($user) {
    global $db, $setting;
    $uid = $user['uid'];
    $password = $user['password'];
    $time = time();
    $sid = tcookie('sid');
    $db->query("UPDATE " . DB_TABLEPRE . "user SET `lastlogin`=$time  WHERE `uid`=$uid"); //��������¼ʱ��
    $db->query("REPLACE INTO " . DB_TABLEPRE . "session (sid,uid,islogin,ip,`time`) VALUES ('$sid',$uid,1,'" . getip() . "',$time)");
    $auth = authcode("$uid\t$password",'ENCODE');
    tcookie('auth', $auth);
    tcookie('loginuser', '');
}

?>