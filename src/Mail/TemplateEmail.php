<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Webvision\NinjaZugferd\Mail;

use App\Jobs\Invoice\CreateUbl;
use Webvision\NinjaZugferd\Jobs\Invoice\CreateZugferd;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use App\Services\PdfMaker\Designs\Utilities\DesignHelpers;
use App\Utils\HtmlEngine;
use App\Utils\TemplateEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Utils\Ninja;

class TemplateEmail extends Mailable
{

    private $build_email;

    private $client;

    private $contact;

    private $company;

    private $invitation;

    public function __construct($build_email, ClientContact $contact, $invitation = null)
    {
        $this->build_email = $build_email;

        $this->contact = $contact;

        $this->client = $contact->client;

        $this->company = $contact->company;

        $this->invitation = $invitation;
    }

    public function build()
    {
        $template_name = 'email.template.'.$this->build_email->getTemplate();

        if ($this->build_email->getTemplate() == 'light' || $this->build_email->getTemplate() == 'dark') {
            $template_name = 'email.template.client';
        }

        if($this->build_email->getTemplate() == 'custom') {
            $this->build_email->setBody(str_replace('$body', $this->build_email->getBody(), $this->client->getSetting('email_style_custom')));
        }

        $settings = $this->client->getMergedSettings();

        if ($this->build_email->getTemplate() !== 'custom') {
            $this->build_email->setBody(
                DesignHelpers::parseMarkdownToHtml($this->build_email->getBody())
            );
        }

        $company = $this->client->company;

        if($this->invitation)
        {
            $html_variables = (new HtmlEngine($this->invitation))->makeValues();
            $signature = str_replace(array_keys($html_variables), array_values($html_variables), $settings->email_signature);
        }
        else
            $signature = $settings->email_signature;

        if(property_exists($settings, 'email_from_name') && strlen($settings->email_from_name) > 1)
            $email_from_name = $settings->email_from_name;
        else
            $email_from_name = $this->company->present()->name();

        $this->from(config('mail.from.address'), $email_from_name);

        if (strlen($settings->bcc_email) > 1) {
            if (Ninja::isHosted()) {

                if($company->account->isPaid()) {
                    $bccs = explode(',', str_replace(' ', '', $settings->bcc_email));
                    $this->bcc(array_slice($bccs, 0, 5));
                }

            } else {
                $this->bcc(explode(',', str_replace(' ', '', $settings->bcc_email)));
            }
        }

        $this->subject(str_replace("<br>", "", $this->build_email->getSubject()))
        ->text('email.template.text', [
            'text_body' => $this->build_email->getTextBody(),
            'whitelabel' => $this->client->user->account->isPaid() ? true : false,
            'settings' => $settings,
        ])
        ->view($template_name, [
            'greeting' => ctrans('texts.email_salutation', ['name' => $this->contact->present()->name()]),
            'body' => $this->build_email->getBody(),
            'footer' => $this->build_email->getFooter(),
            'view_link' => $this->build_email->getViewLink(),
            'view_text' => $this->build_email->getViewText(),
            'title' => '',
            'signature' => $signature,
            'settings' => $settings,
            'company' => $company,
            'whitelabel' => $this->client->user->account->isPaid() ? true : false,
            'logo' => $this->company->present()->logo($settings),
            'links' => $this->build_email->getAttachmentLinks(),
        ]);

        foreach ($this->build_email->getAttachments() as $file) {
            if (array_key_exists('file', $file)) {
                $this->attachData(base64_decode($file['file']), $file['name']);
            } else {
                $this->attach($file['path'], ['as' => $file['name'], 'mime' => null]);
            }
        }

        if($this->invitation && $this->invitation->invoice && $settings->ubl_email_attachment && $this->company->account->hasFeature(Account::FEATURE_PDF_ATTACHMENT)){
            $ubl_string = (new CreateZugferd($this->invitation->invoice))->handle();

            nlog($ubl_string);

            if($ubl_string)
                $this->attachData($ubl_string, $this->invitation->invoice->getFileName('xml'));

        }
        if ($this->invitation && $this->invitation->invoice && $this->invitation->invoice->client->getSetting('enable_e_invoice') && $this->company->account->hasFeature(Account::FEATURE_PDF_ATTACHMENT)) {
            $xml_string = $this->invitation->invoice->service()->getEInvoice($this->invitation->contact);

            if($xml_string) {
                $this->attachData($xml_string, $this->invitation->invoice->getEFileName("xml"));
            }
        
        }

        return $this;
    }
}
