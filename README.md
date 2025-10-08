# A space for collaborative IVENA statistics

[![Testsuite](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/tests.yml/badge.svg)](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/tests.yml) [![Linting](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/lint.yml/badge.svg)](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/lint.yml) [![codecov](https://codecov.io/gh/nplhse/collaborative-ivena-statistics/graph/badge.svg?token=0MQSZG4OTM)](https://codecov.io/gh/nplhse/collaborative-ivena-statistics)

# Requirements
- Webserver (Apache, Nginx, LiteSpeed, IIS, etc.) with PHP 8.4 or higher
- PostgreSQL with Version 16 or higher

# Setup
This project expects you to have local webserver (see requirements) running,
preferably with the symfony binary in your development environment.

## Install from GitHub
1. Launch a **terminal** or **console** and navigate to the webroot folder.
   Clone [this repository from GitHub](https://github.com/nplhse/collaborative-ivena-statistics) to
   a folder in the webroot of your server, e.g. 
   `~/webroot/collaborative-ivena-statistics`.

    ```
    $ cd ~/webroot
    $ git clone https://github.com/nplhse/collaborative-ivena-statistics.git
    ```

2. Install the project with all dependencies by using **make**.

    ```
    $ cd ~/webroot/collaborative-ivena-statistics
    $ make install
    ```

3. You are ready to go, just open the site with your favorite browser!

> [!NOTE]
> Please note that with this instruction you'll get a ready to use development
  application that is populated with some reasonable default data. Due to the 
  very early development state there is no way to install an empty application.

# Contributing
Any contribution to this project is appreciated, whether it is related to
fixing bugs, suggestions or improvements. Feel free to take your part in the
development of this project!

However, you should follow some simple guidelines which you can find in the
[CONTRIBUTING](CONTRIBUTING.md) file. Also, you must agree to the
[Code of Conduct](CODE_OF_CONDUCT.md).

# License
See [LICENSE](LICENSE.md).
