# GLPI AlertCreator Plugin ⏰

**Never miss a follow-up again. Schedule email reminders directly from your tickets.**

AlertCreator is a productivity plugin for **GLPI 11+** that allows technicians to schedule delayed email alerts. Perfect for reminding users to reply, scheduling follow-ups with providers, or setting personal reminders without clogging the ticket timeline.

**Compatibility:** Developed for **GLPI 11+** (Verified on v11.0.5).

## 🚀 Key Features

* **Seamless Integration:** Adds a "Create Alert" button directly in the main Ticket Actions menu (contextual dropdown).
* **Smart Scheduling:** Pick a specific date and time for the email to be sent.
* **Custom Recipient:** Send the alert to the requester, a technician, or any external email address.
* **Automated Delivery:** Relies on GLPI's native Cron engine for reliable delivery (compatible with msmtp, sendmail, SMTP).
* **Professional Formatting:**
    * Customizable logo in email headers.
    * Configurable Base URL for clickable links back to the ticket.

## 🛠️ Configuration

After installation, go to **Administration > AlertCreator** (or via the Plugin configuration page) to set up:

1.  **Base URL:** Ensure links in emails point to your public GLPI address.
2.  **Logo:** Upload your company logo to brand the email notifications.

## ⚙️ Prerequisites

* **GLPI 11+**.
* **GLPI Automatic Actions (Cron):** Ensure the GLPI cron is running (System or GLPI mode) so that alerts are processed on time.
    * *The plugin registers a task named `AlertCreator` that checks for pending emails every minute.*

## 💻 Installation

Clone this repository into your `plugins/` directory:

    cd /var/www/html/glpi/plugins
    git clone https://github.com/jessy-chaila/alertcreator.git

1.  Log in to GLPI.
2.  Go to **Setup > Plugins**.
3.  Click **Install** and then **Enable** for "AlertCreator".
4.  Check **Setup > Automatic Actions** to confirm the `AlertCreator` task is active.

## 🛡️ Rights Management

* **Admin/Tech only:** By default, the functionality is restricted to the "Central" interface (Technicians and Administrators).

## 🤝 Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.
