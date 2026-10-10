# HotCRP image for Anthropic's OSS Scanner (https://github.com/anthropics/oss-scanner).
#
# The scanner builds this with the repository root as the build context, then
# runs the image as root in /src WITH NO NETWORK and no init system. Nothing
# is running when a container starts: `hotcrp-scanctl start` brings up MariaDB
# and nginx/php-fpm for the two sample conferences. See
# devel/oss-scanner/threat_model.md and `hotcrp-scanctl help`.
#
# Try it locally from the repository root:
#   docker build -f devel/oss-scanner/Dockerfile -t hotcrp-scan .
#   docker run --rm -it --network none hotcrp-scan bash
#   # hotcrp-scanctl start && curl -s http://localhost:8080/conf1/ | head

FROM debian:trixie

ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl git procps less file jq \
      php-cli php-mysql php-mbstring php-intl php-curl php-gd php-zip \
      php-gmp php-ldap php-apcu php-xml php-bcmath \
      mariadb-server mariadb-client \
      nginx php-fpm \
      poppler-utils \
      nodejs \
      python3 python3-requests python3-bs4 python3-lxml python3-jwt \
    && rm -rf /var/lib/apt/lists/*

# scanner contract: the checkout lives inside the image, at /src
COPY . /src
WORKDIR /src

RUN install -m 0755 devel/oss-scanner/hotcrp-scanctl /usr/local/bin/hotcrp-scanctl \
    && install -m 0755 devel/oss-scanner/hotcrp-sendmail /usr/local/bin/hotcrp-sendmail \
    && install -D -m 0644 devel/oss-scanner/bashrc.sh /etc/hotcrp-scanctl/bashrc.sh \
    && echo '. /etc/hotcrp-scanctl/bashrc.sh' >> /root/.bashrc

# Create the test-suite databases and the sample conferences, then shut
# MariaDB down cleanly so its data directory is part of the image.
RUN hotcrp-scanctl setup
