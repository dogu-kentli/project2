<?php
class ControllerAccountRegister extends Controller {
    private $error = array();

    public function index() {
        if ($this->customer->isLogged()) {
            $this->response->redirect($this->url->link('account/account', '', true));
        }

        $this->load->language('account/register');
        $this->load->model('account/customer');
        $this->document->setTitle($this->language->get('heading_title'));

        $data = array();
        $data['login'] = $this->url->link('account/login', '', true);
        $data['register'] = $this->url->link('account/register', '', true);
        $data['action'] = $this->url->link('account/register', '', true);
        $data['send_code_url'] = $this->url->link('account/register/sendCode', '', true);
        $data['verify_code_url'] = $this->url->link('account/register/verifyCode', '', true);
        $data['activation_required'] = isset($this->session->data['register_verification']);
        $data['activation_code'] = isset($this->request->post['activation_code']) ? $this->request->post['activation_code'] : '';

        foreach (array('firstname', 'lastname', 'dogum_tarihi', 'email', 'telephone', 'password', 'confirm') as $field) {
            $data[$field] = isset($this->request->post[$field]) ? $this->request->post[$field] : '';
        }

        foreach (array('firstname', 'lastname', 'dogum', 'email', 'telephone', 'password', 'confirm', 'activation_code') as $field) {
            $data['error_' . $field] = isset($this->error[$field]) ? $this->error[$field] : '';
        }
        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
        $data['agree'] = !empty($this->request->post['agree']);
        $data['text_agree'] = '';
        $data['customer_groups'] = array();
        $data['custom_fields'] = array();
        $data['register_custom_field'] = array();
        $data['captcha'] = '';
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['header'] = $this->load->controller('common/header');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('account/register', $data));
    }

    public function sendCode() {
        $this->load->language('account/register');
        $this->load->model('account/customer');
        $this->request->post = $this->request->post ? $this->request->post : array();
        $this->error = array();

        if (!$this->validate()) {
            $this->json(array('success' => false, 'errors' => $this->error));
            return;
        }

        try {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } catch (Exception $e) {
            $code = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        }

        $pending = $this->request->post;
        unset($pending['activation_code']);
        $this->session->data['register_verification'] = array(
            'code' => $code,
            'expires' => time() + 900,
            'data' => $pending
        );

        $mail = new Mail($this->config->get('config_mail_engine'));
        $mail->parameter = $this->config->get('config_mail_parameter');
        $mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
        $mail->smtp_username = $this->config->get('config_mail_smtp_username');
        $mail->smtp_password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
        $mail->smtp_port = $this->config->get('config_mail_smtp_port');
        $mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');
        $mail->setTo($pending['email']);
        $mail->setFrom($this->config->get('config_email'));
        $mail->setSender($this->config->get('config_name'));
        $mail->setSubject('E-posta aktivasyon kodunuz');
        $mail->setText("Merhaba {$pending['firstname']},\n\nKayıt işleminizi tamamlamak için aktivasyon kodunuz: {$code}\n\nBu kod 15 dakika geçerlidir.");
        $mail->send();

        $this->json(array('success' => true, 'message' => 'Aktivasyon kodu e-posta adresinize gönderildi.'));
    }

    public function verifyCode() {
        $this->load->model('account/customer');
        $this->response->addHeader('Content-Type: application/json');
        $pending = isset($this->session->data['register_verification']) ? $this->session->data['register_verification'] : null;
        $code = isset($this->request->post['activation_code']) ? trim((string)$this->request->post['activation_code']) : '';

        if (!$pending) {
            $this->json(array('success' => false, 'message' => 'Önce kayıt formunu göndererek aktivasyon kodu isteyin.'));
            return;
        }
        if ($pending['expires'] < time()) {
            unset($this->session->data['register_verification']);
            $this->json(array('success' => false, 'message' => 'Aktivasyon kodunun süresi doldu.'));
            return;
        }
        if (!hash_equals($pending['code'], $code)) {
            $this->json(array('success' => false, 'message' => 'Aktivasyon kodu hatalı.'));
            return;
        }

        $this->model_account_customer->addCustomer($pending['data']);
        $this->model_account_customer->deleteLoginAttempts($pending['data']['email']);
        unset($this->session->data['register_verification']);
        $this->customer->login($pending['data']['email'], $pending['data']['password']);
        unset($this->session->data['guest']);
        $this->json(array('success' => true, 'redirect' => $this->url->link('account/success')));
    }

    private function json($output) {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($output));
    }

    private function validate() {
        if (utf8_strlen(trim(isset($this->request->post['firstname']) ? $this->request->post['firstname'] : '')) < 1 || utf8_strlen(trim($this->request->post['firstname'])) > 32) $this->error['firstname'] = $this->language->get('error_firstname');
        if (utf8_strlen(trim(isset($this->request->post['lastname']) ? $this->request->post['lastname'] : '')) < 1 || utf8_strlen(trim($this->request->post['lastname'])) > 32) $this->error['lastname'] = $this->language->get('error_lastname');
        if (empty($this->request->post['dogum_tarihi'])) $this->error['dogum'] = 'Doğum tarihinizi giriniz.';
        if (utf8_strlen(isset($this->request->post['email']) ? $this->request->post['email'] : '') > 96 || !filter_var(isset($this->request->post['email']) ? $this->request->post['email'] : '', FILTER_VALIDATE_EMAIL)) $this->error['email'] = $this->language->get('error_email');
        if (!$this->error && $this->model_account_customer->getTotalCustomersByEmail($this->request->post['email'])) $this->error['warning'] = $this->language->get('error_exists');
        if (utf8_strlen(isset($this->request->post['telephone']) ? $this->request->post['telephone'] : '') < 3 || utf8_strlen($this->request->post['telephone']) > 32) $this->error['telephone'] = $this->language->get('error_telephone');
        if (utf8_strlen(html_entity_decode(isset($this->request->post['password']) ? $this->request->post['password'] : '', ENT_QUOTES, 'UTF-8')) < 4 || utf8_strlen($this->request->post['password']) > 40) $this->error['password'] = $this->language->get('error_password');
        if (!isset($this->request->post['confirm']) || $this->request->post['confirm'] != $this->request->post['password']) $this->error['confirm'] = $this->language->get('error_confirm');
        if ($this->config->get('config_account_id') && empty($this->request->post['agree'])) $this->error['warning'] = 'Kayıt olmak için üyelik koşullarını kabul etmelisiniz.';
        return !$this->error;
    }
}
