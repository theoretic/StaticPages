
# StaticPages
Lazy static pages generator for Processwire CMS.

## How does it work

Normally Processwire renders any page any time it's requested. StaticPages module allows Processwire to render certain pages only once. When it happens, StaticPages creates a static html file located corresponding to the URL which has been requested. The following requeststs to this URL will not be handled by Processwire. The previously created html file will be returned by the server.

Module reacts to the following events:

- Page render: a new static html file is created.
- Page save: all previously generated html files will be deleted.
- Page delete: all previously generated html files will be deleted.

## Installation

- Download or git clone this repository
- Copy the StaticPages folder inside /my-processwire-project/site/modules directory
- Go to PW admin page
- Go to Modules admin page
- Hit Refresh button
- Locate the newly found StaticPages module in the Site tab
- Hit Install button
- Modify module settings at will
- **Important!**
	- Locate .htaccess file inside /my-processwire-project/
	- Locate the following string:
		RewriteCond %{REQUEST_FILENAME} !-d
	- Comment it with the \# symbol
	- Save the file

## Warning

However tested and used on several websites, hosting platforms and environments, this mode should be considered as beta release. Use it at Your own risk. Since StaticPages may perform bulk files and folders deletion, it's strongly advised to make the full website backup before the first use.