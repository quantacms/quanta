![alt text](https://www.quanta.org/assets/img/q_cms.png)
# Quanta Cms #
An innovative, DB-free Content Management System for web and application development, based on the principles of Quantum physics.

Originally designed by Aldo Tripiciano in 2015, Quanta is now proudly free and open source.

Detailed info is available at the official website: https://www.quanta.org

The Author
----------------------------------
Aldo Tripiciano is a seasoned italian IT Developer, consulting for 15+ years on the major CMS players (Drupal, Wordpress, Joomla, etc.) for global companies and organisations such as the United Nations and the European Commission. 
After mastering the top tools in the market, he decided to go one step beyond and build something new, to better fit the new generation of the Web. 


Quanta's Features
----------------------------------
Quanta is a CMS thought for developers, offering a number of features out of the box:

- Pre-defined installation profiles (including modules, themes and general entities)
- A customizable UI backend ("Shadow"), also used for overlay forms
- Inline editing of content
- A batch tool ("Doctor") for install, update, diagnostic and repair
- User management tools
- Inline Form management
- Workflow management (Draft->Published statuses, etc.)
- Taxonomy management
- Multilingualism / Internationalization 
- Widget / Web service tools
- NO-database approach, completely eliminating DB-related attacks such as SQL injections
- Big applications are easy to distribute on multiple machines, Virtualized or Cloud-based servers, thanks to the file-system based data model
- Pre-defined integrated functionalities (Blog, Carousels and Slideshows, Media Playlists, Maps, XML Sitemaps, Widgets, and much more)

Quanta's Architecture
----------------------------------
Quanta's Architecture is built on PHP7, yet not following the traditional MVC model nor standard PHP design patterns. 
Its main characteristics are as follows: 

* a 100% file-system based architecture structured on  hierarchical system folders 📁 (no DB or SQL involved). 
* Internal caching and indexation of directories through an internal vocabulary
* JSON storage of data and metadata
* 100% Object Oriented PHP
* Template engine allowing creation and override individual template for individual or multiple entities
* qTags: special markup allowing creation of tags that are incapsulated into templates, allowing the creation of complex applications with huge reduction of coding times
* CSS Grid approach natively supported by qTags
* Node-level access control (roles, permissions, etc.)
* Views system


Pre-Requisites
----------------------------------
Quanta can only be installed on any UNIX-based OS (Linux, OSx, etc.). 

### General Requisites:
__Apache 2.4+__ or __Nginx 1.15+__
__PHP 5.6+__ (7 strongly advised!) including libraries: __GD__, __CURL__
__Composer__

### For Apache users:
The __rewrite__ and __headers__ modules must be enabled.

### For Windows / XAMP users:
As Quanta only runs on UNIX, in order to run Quanta on Windows, you will have to install a VM (VMware, VirtualBox, etc.) with your distribution of choice. 


Installation
-----------------
Quick kickstart guide:

1. Clone the latest release of the Quanta repo

2. Create a host pointing to your quanta folder (i.e. myproject.com => /var/www/quanta)

3. Run Doctor: 
```bash
./doctor myproject.com
```
and follow the steps until installation is completed.

4. run Composer:
```bash
composer install
```

5. Done! Check your brand new site at http://myproject

and start customizing it. 

Detailed information about Quanta's installation process is available on the website: https://www.quanta.org/installation-instructions/


Customization
-----------------
Once you have, you can start: 
* structuring your folders structure -> https://www.quanta.org/approaching-quantas-structure-nodes-folders-and-datajson-files/
* playing around with qTags -> https://www.quanta.org/what-is-a-quanta-tag-qtag/
* creating a basic layout -> https://www.quanta.org/the-index-html-file/ 
* building up great templates -> https://www.quanta.org/the-tpl-html-file/
* creating a custom module -> https://www.quanta.org/creating-a-custom-quanta-module/

... and become a Quanta pro by having a look at:
* the documentation -> https://www.quanta.org/documentation/
* the tutorials  -> https://www.quanta.org/tutorial/

Support
-----------------
Found any issue? Got any idea? 
Quanta's contributors and enthusiasts are always happy to help.

* the FAQ -> https://www.quanta.org/faq/
* the Community -> https://www.quanta.org/community/
* the Facebook Group -> https://www.facebook.com/groups/quantacms

If you feel like something needs attention, don't hesitate in opening a new issue in the github repository:
https://github.com/quantacms/quanta

Quanta needs you to make every day new steps to become the best CMS ever created!
