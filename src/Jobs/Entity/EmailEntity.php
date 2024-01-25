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

namespace Webvision\NinjaZugferd\Jobs\Entity;

use App\Events\Invoice\InvoiceReminderWasEmailed;
use App\Events\Invoice\InvoiceWasEmailed;
use App\Events\Invoice\InvoiceWasEmailedAndFailed;
use App\Jobs\Mail\EntityFailedSendMailer;
use App\Jobs\Mail\NinjaMailerJob;
use App\Jobs\Mail\NinjaMailerObject;
use App\Libraries\MultiDB;
use Webvision\NinjaZugferd\Mail\TemplateEmail;
use App\Models\Activity;
use App\Models\Company;
use App\Models\CreditInvitation;
use App\Models\InvoiceInvitation;
use App\Models\QuoteInvitation;
use App\Models\RecurringInvoiceInvitation;
use App\Utils\HtmlEngine;
use App\Utils\Ninja;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\App;
use App\Jobs\Invoice\CreateUbl;


/*Multi Mailer implemented*/

class EmailEntity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $invitation; //The entity invitation

    public $company; //The company

    public $settings; //The settings object

    public $entity_string; //The entity string ie. invoice, quote, credit

    public $reminder_template; //The base template we are using

    public $entity; //The entity object

    public $html_engine; //The HTMLEngine object

    public $email_entity_builder; //The email builder which merges the template and text

    public $template_data; //The data to be merged into the template

    public $tries = 1;

    /**
     * EmailEntity constructor.
     *
     *
     * @param Invitation $invitation
     * @param Company    $company
     * @param ?string    $reminder_template
     * @param array      $template_data
     */
    public function __construct($invitation, Company $company, ?string $reminder_template = null, $template_data = null)
    {
        $this->company = $company;

        $this->invitation = $invitation;

        $this->settings = $invitation->contact->client->getMergedSettings();

        $this->entity_string = $this->resolveEntityString();

        $this->entity = $invitation->{$this->entity_string};

        $this->reminder_template = $reminder_template ?: $this->entity->calculateTemplate($this->entity_string);

        $this->html_engine = new HtmlEngine($invitation);

        $this->template_data = $template_data;

        $this->email_entity_builder = $this->resolveEmailBuilder();
    }

    /**
     * Execute the job.
     *
     *
     * @return void
     */
    public function handle()
    {
        /* Don't fire emails if the company is disabled */
        if ($this->company->is_disabled){
            return true;
        }

        $this->email_entity_builder = $this->resolveEmailBuilder();

        /* Set DB */
        MultiDB::setDB($this->company->db);

        App::forgetInstance('translator');
        $t = app('translator');
        App::setLocale($this->invitation->contact->preferredLocale());
        $t->replace(Ninja::transformTranslations($this->settings));

        /* Mark entity sent */
        $this->entity->service()->markSent()->save();

        $nmo = new NinjaMailerObject;
        $nmo->mailable = new TemplateEmail($this->email_entity_builder, $this->invitation->contact->withoutRelations(), $this->invitation->withoutRelations());
        $nmo->company = $this->company->withoutRelations();
        $nmo->settings = $this->settings;
        $nmo->to_user = $this->invitation->contact->withoutRelations();
        $nmo->entity_string = $this->entity_string;
        $nmo->invitation = $this->invitation->withoutRelations();
        $nmo->reminder_template = $this->reminder_template;
        $nmo->entity = $this->entity->withoutRelations();
        
        /**
         * Append file
         */
        //$nmo->mailable->attachData('Test', $this->invitation->invoice->getFileName('xml'));
        NinjaMailerJob::dispatch($nmo);
    }

    private function resolveEntityString() :string
    {
        if ($this->invitation instanceof InvoiceInvitation) {
            return 'invoice';
        } elseif ($this->invitation instanceof QuoteInvitation) {
            return 'quote';
        } elseif ($this->invitation instanceof CreditInvitation) {
            return 'credit';
        } elseif ($this->invitation instanceof RecurringInvoiceInvitation) {
            return 'recurring_invoice';
        }
    }

    /* Switch statement to handle failure notifications */
    private function entityEmailFailed($message)
    {
        switch ($this->entity_string) {
            case 'invoice':
                event(new InvoiceWasEmailedAndFailed($this->invitation, $this->company, $message, $this->reminder_template, Ninja::eventVars(auth()->user() ? auth()->user()->id : null)));
                break;

            default:
                # code...
                break;
        }
    }

    /* Builds the email builder object */
    private function resolveEmailBuilder()
    {
        $class = 'App\Mail\Engine\\' . ucfirst(Str::camel($this->entity_string)) . "EmailEngine";

        return (new $class($this->invitation, $this->reminder_template, $this->template_data))->build();
    }
}
