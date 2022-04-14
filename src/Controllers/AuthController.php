<?php

namespace App\Controllers;

use App\Models\{
    User,
    Setting,
    InviteCode,
    EmailVerify
};
use App\Utils\{
    GA,
    Hash,
    Check,
    Tools,
    TelegramSessionManager
};
use App\Services\{
    Auth,
    Captcha,
    Mail,
    Config
};
use voku\helper\AntiXSS;
use Exception;
use Ramsey\Uuid\Uuid;
use Slim\Http\{
    Request,
    Response
};

/**
 *  AuthController
 */
class AuthController extends BaseController
{
    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function login($request, $response, $args)
    {
        $captcha = Captcha::generate();

        if ($_ENV['enable_telegram_login'] === true) {
            $login_text   = TelegramSessionManager::add_login_session();
            $login        = explode('|', $login_text);
            $login_token  = $login[0];
            $login_number = $login[1];
        } else {
            $login_token  = '';
            $login_number = '';
        }

        if (Setting::obtain('enable_login_captcha') == true) {
            $geetest_html = $captcha['geetest'];
        } else {
            $geetest_html = null;
        }

        return $this->view()
            ->assign('geetest_html', $geetest_html)
            ->assign('login_token', $login_token)
            ->assign('login_number', $login_number)
            ->assign('telegram_bot', $_ENV['telegram_bot'])
            ->assign('base_url', $_ENV['baseUrl'])
            ->assign('recaptcha_sitekey', $captcha['recaptcha'])
            ->display('auth/login.tpl');
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function getCaptcha($request, $response, $args)
    {
        $captcha = Captcha::generate();
        return $response->withJson([
            'recaptchaKey' => $captcha['recaptcha'],
            'GtSdk'        => $captcha['geetest'],
            'respon'       => 1,
        ]);
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function loginHandle($request, $response, $args)
    {
        $email      = trim($request->getParam('email'));
        $email      = strtolower($email);
        $passwd     = $request->getParam('passwd');
        $code       = $request->getParam('code');
        $rememberMe = $request->getParam('remember_me');

        if (Setting::obtain('enable_login_captcha') == true) {
            $ret = Captcha::verify($request->getParams());
            if (!$ret) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '系统无法接受您的验证结果，请刷新页面后重试。'
                ]);
            }
        }

        $user = User::where('email', $email)->first();
        if ($user == null) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '邮箱不存在'
            ]);
        }

        if (!Hash::checkPassword($user->pass, $passwd)) {
            // 记录登录失败
            $user->collectLoginIP($_SERVER['REMOTE_ADDR'], 1);
            return $response->withJson([
                'ret' => 0,
                'msg' => '邮箱或者密码错误'
            ]);
        }

        if ($user->ga_enable == 1) {
            $ga    = new GA();
            $rcode = $ga->verifyCode($user->ga_token, $code);
            if (!$rcode) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '两步验证码错误，如果您是丢失了生成器或者错误地设置了这个选项，您可以尝试重置密码，即可取消这个选项。'
                ]);
            }
        }

        $time = 3600 * 24;
        if ($rememberMe) {
            $time = 3600 * 24 * ($_ENV['cookie_expiration_time'] ?: 7);
        }

        Auth::login($user->id, $time);
        // 记录登录成功
        $user->collectLoginIP($_SERVER['REMOTE_ADDR']);

        return $response->withJson([
            'ret' => 1,
            'msg' => '登录成功'
        ]);
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function qrcode_loginHandle($request, $response, $args)
    {
        $token  = $request->getParam('token');
        $number = $request->getParam('number');

        $ret = TelegramSessionManager::step2_verify_login_session($token, $number);
        if ($ret === 0) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '此令牌无法被使用。'
            ]);
        }

        $user = User::find($ret);

        Auth::login($user->id, 3600 * 24);
        // 记录登录成功
        $user->collectLoginIP($_SERVER['REMOTE_ADDR']);

        return $response->withJson([
            'ret' => 1,
            'msg' => '登录成功'
        ]);
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function register($request, $response, $next)
    {
        $ary  = $request->getQueryParams();
        $code = '';
        if (isset($ary['code'])) {
            $antiXss = new AntiXSS();
            $code    = $antiXss->xss_clean($ary['code']);
        }

        $captcha = Captcha::generate();

        if ($_ENV['enable_telegram_login'] === true) {
            $login_text   = TelegramSessionManager::add_login_session();
            $login        = explode('|', $login_text);
            $login_token  = $login[0];
            $login_number = $login[1];
        } else {
            $login_token  = '';
            $login_number = '';
        }

        if (Setting::obtain('enable_reg_captcha') == true) {
            $geetest_html = $captcha['geetest'];
        } else {
            $geetest_html = null;
        }

        return $this->view()
            ->assign('geetest_html', $geetest_html)
            ->assign('enable_email_verify', Setting::obtain('reg_email_verify'))
            ->assign('code', $code)
            ->assign('recaptcha_sitekey', $captcha['recaptcha'])
            ->assign('telegram_bot', $_ENV['telegram_bot'])
            ->assign('base_url', $_ENV['baseUrl'])
            ->assign('login_token', $login_token)
            ->assign('login_number', $login_number)
            ->display('auth/register.tpl');
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function sendVerify($request, $response, $next)
    {
        if (Setting::obtain('reg_email_verify')) {
            $email = trim($request->getParam('email'));
            $email = strtolower($email);
            if ($email == '') {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '未填写邮箱'
                ]);
            }
            // check email format
            $check_res = Check::isEmailLegal($email);
            if ($check_res['ret'] == 0) {
                return $response->withJson($check_res);
            }
            $user = User::where('email', $email)->first();
            if ($user != null) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '此邮箱已经注册'
                ]);
            }
            $ipcount = EmailVerify::where('ip', '=', $_SERVER['REMOTE_ADDR'])->where('expire_in', '>', time())->count();
            if ($ipcount >= Setting::obtain('email_verify_ip_limit')) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '此IP请求次数过多'
                ]);
            }
            $mailcount = EmailVerify::where('email', '=', $email)->where('expire_in', '>', time())->count();
            if ($mailcount >= 3) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '此邮箱请求次数过多'
                ]);
            }
            $code          = Tools::genRandomNum(6);
            $ev            = new EmailVerify();
            $ev->expire_in = time() + Setting::obtain('email_verify_ttl');
            $ev->ip        = $_SERVER['REMOTE_ADDR'];
            $ev->email     = $email;
            $ev->code      = $code;
            $ev->save();
            try {
                Mail::send(
                    $email,
                    $_ENV['appName'] . '- 验证邮件',
                    'auth/verify.tpl',
                    [
                        'code' => $code,
                        'expire' => date('Y-m-d H:i:s', time() + Setting::obtain('email_verify_ttl'))
                    ],
                    []
                );
            } catch (Exception $e) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '验证码发送失败'
                ]);
            }
            return $response->withJson([
                'ret' => 1,
                'msg' => '验证码发送成功，请查收'
            ]);
        }

        return $response->withJson([
            'ret' => 0,
            'msg' => '没有启用邮箱验证'
        ]);
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function register_helper($name, $email, $passwd, $code, $imtype, $imvalue, $telegram_id)
    {
        if (Setting::obtain('reg_mode') == 'close') {
            $res['ret'] = 0;
            $res['msg'] = '暂时不对外开放注册';
            return $res;
        }

        if ($code == '') {
            $res['ret'] = 0;
            $res['msg'] = '注册需要填写邀请码';
            return $res;
        }

        $c = InviteCode::where('code', $code)->first();
        if ($c == null) {
            if (Setting::obtain('reg_mode') == 'invite') {
                $res['ret'] = 0;
                $res['msg'] = '这个邀请码不存在';
                return $res;
            }
        } elseif ($c->user_id != 0) {
            $gift_user = User::where('id', $c->user_id)->first();
            if ($gift_user == null) {
                $res['ret'] = 0;
                $res['msg'] = '邀请码已失效';
                return $res;
            }

            if ($gift_user->invite_num == 0) {
                $res['ret'] = 0;
                $res['msg'] = '邀请码不可用';
                return $res;
            }
        }

        $configs = Setting::getClass('register');
        // do reg user
        $user                       = new User();
        $antiXss                    = new AntiXSS();
        $current_timestamp          = time();

        $user->user_name            = $antiXss->xss_clean($name);
        $user->email                = $email;
        $user->pass                 = Hash::passwordHash($passwd);
        $user->passwd               = Tools::genRandomChar(16);
        $user->uuid                 = Uuid::uuid3(Uuid::NAMESPACE_DNS, $email . '|' . $current_timestamp);
        $user->port                 = Tools::getAvPort();
        $user->t                    = 0;
        $user->u                    = 0;
        $user->d                    = 0;
        $user->method               = $configs['sign_up_for_method'];
        $user->protocol             = $configs['sign_up_for_protocol'];
        $user->protocol_param       = $configs['sign_up_for_protocol_param'];
        $user->obfs                 = $configs['sign_up_for_obfs'];
        $user->obfs_param           = $configs['sign_up_for_obfs_param'];
        $user->forbidden_ip         = $_ENV['reg_forbidden_ip'];
        $user->forbidden_port       = $_ENV['reg_forbidden_port'];
        $user->im_type              = $imtype;
        $user->im_value             = $antiXss->xss_clean($imvalue);

        $user->transfer_enable      = Tools::toGB($configs['sign_up_for_free_traffic']);
        $user->invite_num           = $configs['sign_up_for_invitation_codes'];
        $user->auto_reset_day       = '0';
        $user->auto_reset_bandwidth = '0';
        $user->money                = 0;
        $user->sendDailyMail        = $configs['sign_up_for_daily_report'];

        //dumplin：填写邀请人，写入邀请奖励
        $user->ref_by = 0;
        if ($c != null && $c->user_id != 0) {
            $invitation = Setting::getClass('invite');
            // 设置新用户
            $user->ref_by = $c->user_id;
            // 给邀请人反流量
            if (($gift_user->invite_num - 1) >= 0) {
                --$gift_user->invite_num;
                // 避免设置为不限制邀请次数的值 -1 发生变动
            }
            $gift_user->save();
        }

        if ($telegram_id) {
            $user->telegram_id = $telegram_id;
        }

        $ga                     = new GA();
        $secret                 = $ga->createSecret();
        $user->ga_token         = $secret;
        $user->ga_enable        = 0;
        $user->class_expire     = date('Y-m-d H:i:s', time() + $configs['sign_up_for_class_time'] * 86400);
        $user->class            = $configs['sign_up_for_class'];
        $user->node_connector   = $configs['connection_device_limit'];
        $user->node_speedlimit  = $configs['connection_rate_limit'];
        $user->expire_in        = date('Y-m-d H:i:s', time() + $configs['sign_up_for_free_time'] * 86400);
        $user->reg_date         = date('Y-m-d H:i:s');
        $user->reg_ip           = $_SERVER['REMOTE_ADDR'];
        $user->theme            = $_ENV['theme'];
        $groups                 = explode(',', $_ENV['random_group']);
        $user->node_group       = $groups[array_rand($groups)];

        if ($user->save()) {
            Auth::login($user->id, 3600);
            $user->collectLoginIP($_SERVER['REMOTE_ADDR']);

            $res['ret'] = 1;
            $res['msg'] = '注册成功！正在进入登录界面';
            return $res;
        }

        $res['ret'] = 0;
        $res['msg'] = '未知错误';
        return $res;
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function registerHandle($request, $response, $args)
    {
        if (Setting::obtain('reg_mode') == 'close') {
            return $response->withJson([
                'ret' => 0,
                'msg' => '未开放注册。'
            ]);
        }

        $name      = $request->getParam('name');
        $email     = $request->getParam('email');
        $email     = trim($email);
        $email     = strtolower($email);
        $passwd    = $request->getParam('passwd');
        $repasswd  = $request->getParam('repasswd');
        $code      = trim($request->getParam('code'));
        $emailcode = $request->getParam('emailcode');
        $emailcode = trim($emailcode);

        if ($_ENV['enable_reg_im'] === true) {
            $imtype  = $request->getParam('im_type');
            $imvalue = $request->getParam('im_value');
            if ($imtype == '' || $imvalue == '') {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '请填上你的联络方式'
                ]);
            }
            $user = User::where('im_value', $imvalue)->where('im_type', $imtype)->first();
            if ($user != null) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '此联络方式已注册'
                ]);
            }
        } else {
            $imtype  = 1;
            $imvalue = '';
        }

        if (Setting::obtain('enable_reg_captcha') == true) {
            $ret = Captcha::verify($request->getParams());
            if (!$ret) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '系统无法接受您的验证结果，请刷新页面后重试。'
                ]);
            }
        }

        // check email format
        $check_res = Check::isEmailLegal($email);
        if ($check_res['ret'] == 0) {
            return $response->withJson($check_res);
        }
        // check email
        $user = User::where('email', $email)->first();
        if ($user != null) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '邮箱已经被注册了'
            ]);
        }

        if (Setting::obtain('reg_email_verify')) {
            $mailcount = EmailVerify::where('email', '=', $email)->where('code', '=', $emailcode)->where('expire_in', '>', time())->first();
            if ($mailcount == null) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '您的邮箱验证码不正确'
                ]);
            }
        }

        // check pwd length
        if (strlen($passwd) < 8) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '密码请大于8位'
            ]);
        }

        // check pwd re
        if ($passwd != $repasswd) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '两次密码输入不符'
            ]);
        }

        if (Setting::obtain('reg_email_verify')) {
            EmailVerify::where('email', $email)->delete();
        }

        return $response->withJson(
            $this->register_helper($name, $email, $passwd, $code, $imtype, $imvalue, 0)
        );
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function logout($request, $response, $next)
    {
        Auth::logout();
        return $response->withStatus(302)->withHeader('Location', '/auth/login');
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function qrcode_check($request, $response, $args)
    {
        $token  = $request->getParam('token');
        $number = $request->getParam('number');
        $user   = Auth::getUser();
        if ($user->isLogin) {
            return $response->withJson([
                'ret' => 0
            ]);
        }
        if ($_ENV['enable_telegram_login'] === true) {
            $ret = TelegramSessionManager::check_login_session($token, $number);
            $res['ret'] = $ret;
            return $response->withJson($res);
        }
        return $response->withJson([
            'ret' => 0
        ]);
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function telegram_oauth($request, $response, $args)
    {
        if ($_ENV['enable_telegram_login'] === true) {
            $auth_data = $request->getQueryParams();
            if ($this->telegram_oauth_check($auth_data) === true) { // Looks good, proceed.
                $telegram_id = $auth_data['id'];
                $user        = User::query()->where('telegram_id', $telegram_id)->firstOrFail(); // Welcome Back :)
                if ($user == null) {
                    return $this->view()
                        ->assign('title', '您需要先进行邮箱注册后绑定Telegram才能使用授权登录')
                        ->assign('message', '很抱歉带来的不便，请重新试试')
                        ->assign('redirect', '/auth/login')
                        ->display('telegram_error.tpl');
                }
                Auth::login($user->id, 3600);

                // 记录登录成功
                $user->collectLoginIP($_SERVER['REMOTE_ADDR']);

                // 登陆成功！
                return $this->view()
                    ->assign('title', '登录成功')
                    ->assign('message', '正在前往仪表盘')
                    ->assign('redirect', '/user')
                    ->display('telegram_success.tpl');
            }
            // 验证失败
            return $this->view()
                ->assign('title', '登陆超时或非法构造信息')
                ->assign('message', '很抱歉带来的不便，请重新试试')
                ->assign('redirect', '/auth/login')
                ->display('telegram_error.tpl');
        }
        return $response->withRedirect('/404');
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    private function telegram_oauth_check($auth_data)
    {
        $check_hash = $auth_data['hash'];
        $bot_token  = $_ENV['telegram_token'];
        unset($auth_data['hash']);
        $data_check_arr = [];
        foreach ($auth_data as $key => $value) {
            $data_check_arr[] = $key . '=' . $value;
        }
        sort($data_check_arr);
        $data_check_string = implode("\n", $data_check_arr);
        $secret_key        = hash('sha256', $bot_token, true);
        $hash              = hash_hmac('sha256', $data_check_string, $secret_key);
        if (strcmp($hash, $check_hash) !== 0) {
            return false; // Bad Data :(
        }

        if ((time() - $auth_data['auth_date']) > 300) { // Expire @ 5mins
            return false;
        }

        return true; // Good to Go
    }
}
