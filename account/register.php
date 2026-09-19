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

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            // The second submit confirms the code sent to the email address.
            if (isset($this->session->data['register_verification']) && isset($this->request->post['activation_code'])) {
                $pending = $this->session->data['register_verification'];
                $code = trim((string)$this->request->post['activation_code']);

                if ($pending['expires'] < time()) {
                    $this->error['warning'] = 'Aktivasyon kodunun süresi doldu. Lütfen formu yeniden gönderin.';
                    unset($this->session->data['register_verification']);
                } elseif (!hash_equals($pending['code'], $code)) {
                    $this->error['activation_code'] = 'Aktivasyon kodu hatalı.';
                } else {
                    $customer_id = $this->model_account_customer->addCustomer($pending['data']);
                    $this->model_account_customer->deleteLoginAttempts($pending['data']['email']);
                    unset($this->session->data['register_verification']);
                    $this->customer->login($pending['data']['email'], $pending['data']['password']);
                    unset($this->session->data['guest']);
                    $this->response->redirect($this->url->link('account/success'));
                }
            } elseif ($this->validate()) {
                $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $pending_data = $this->request->post;
                unset($pending_data['activation_code']);

                $this->session->data['register_verification'] = array(
                    'code' => $code,
                    'expires' => time() + 15 * 60,
                    'data' => $pending_data
                );

                $mail = new Mail($this->config->get('config_mail_engine'));
                $mail->parameter = $this->config->get('config_mail_parameter');
                $mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
                $mail->smtp_username = $this->config->get('config_mail_smtp_username');
                $mail->smtp_password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
                $mail->smtp_port = $this->config->get('config_mail_smtp_port');
                $mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');
                $mail->setTo($pending_data['email']);
                $mail->setFrom($this->config->get('config_email'));
                $mail->setSender($this->config->get('config_name'));
                $mail->setSubject('E-posta aktivasyon kodunuz');
                $mail->setText("Merhaba {$pending_data['firstname']},\n\nKayıt işleminizi tamamlamak için aktivasyon kodunuz: {$code}\n\nBu kod 15 dakika geçerlidir.");
                $mail->send();

                $this->error['warning'] = 'Aktivasyon kodu e-posta adresinize gönderildi. Kayıt işlemini tamamlamak için kodu giriniz.';
            }
        }

        $data['breadcrumbs'] = array(
            array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home')),
            array('text' => $this->language->get('text_account'), 'href' => $this->url->link('account/account', '', true)),
            array('text' => $this->language->get('text_register'), 'href' => $this->url->link('account/register', '', true))
        );
        $data['text_account_already'] = sprintf($this->language->get('text_account_already'), $this->url->link('account/login', '', true));
        $data['login'] = $this->url->link('account/login', '', true);
        $data['register'] = $this->url->link('account/register', '', true);
        $data['action'] = $this->url->link('account/register', '', true);
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

    private function validate() {
        if (utf8_strlen(trim($this->request->post['firstname'])) < 1 || utf8_strlen(trim($this->request->post['firstname'])) > 32) $this->error['firstname'] = $this->language->get('error_firstname');
        if (utf8_strlen(trim($this->request->post['lastname'])) < 1 || utf8_strlen(trim($this->request->post['lastname'])) > 32) $this->error['lastname'] = $this->language->get('error_lastname');
        if (empty($this->request->post['dogum_tarihi'])) $this->error['dogum'] = 'Doğum tarihinizi giriniz.';
        if (utf8_strlen($this->request->post['email']) > 96 || !filter_var($this->request->post['email'], FILTER_VALIDATE_EMAIL)) $this->error['email'] = $this->language->get('error_email');
        if ($this->model_account_customer->getTotalCustomersByEmail($this->request->post['email'])) $this->error['warning'] = $this->language->get('error_exists');
        if (utf8_strlen($this->request->post['telephone']) < 3 || utf8_strlen($this->request->post['telephone']) > 32) $this->error['telephone'] = $this->language->get('error_telephone');
        if (utf8_strlen(html_entity_decode($this->request->post['password'], ENT_QUOTES, 'UTF-8')) < 4 || utf8_strlen(html_entity_decode($this->request->post['password'], ENT_QUOTES, 'UTF-8')) > 40) $this->error['password'] = $this->language->get('error_password');
        if ($this->request->post['confirm'] != $this->request->post['password']) $this->error['confirm'] = $this->language->get('error_confirm');
        if ($this->config->get('config_account_id') && empty($this->request->post['agree'])) $this->error['warning'] = 'Kayıt olmak için üyelik koşullarını kabul etmelisiniz.';
        return !$this->error;
    }

    public function customfield() {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode(array()));
    }
}
