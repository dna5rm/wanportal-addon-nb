#!/bin/bash
#
# import_ssl_cert.sh - wanportal wrapper for import.yml.
#
# Takes a JSON payload as $1: common_name and certificate (PEM text). The
# certificate goes to a temp file (chmod 600, removed on exit) so the newlines
# survive, and the playbook gets it via cert_file. VAULT_PASS, NETBOX_TOKEN
# and NETBOX_URL pass through from the environment. JSON requests swallow the
# playbook output and rely on the exit code; other requests print a banner and
# the playbook output.
#
# Needs jq plus the python packages ansible and fqdn.

export ANSIBLE_PATH="$(dirname "${0}")"
export ANSIBLE_HOME=/tmp/.ansible-$(id -u)
export ANSIBLE_LOCAL_TEMP=/tmp/.ansible-$(id -u)/tmp
export ANSIBLE_REMOTE_TEMP=/tmp/.ansible-$(id -u)/tmp

common_name=$(jq -r '.common_name' <<< "${1}")
certificate=$(jq -r '.certificate' <<< "${1}")

# Write certificate to temp file to preserve newlines
tmp_file=$(mktemp)
chmod 600 "${tmp_file}"
echo "${certificate}" > "${tmp_file}"
trap 'rm -f "${tmp_file}"' EXIT

if echo "${HTTP_ACCEPT}" | grep -qE 'application/json|\*/\*'; then

    ANSIBLE_STDOUT_CALLBACK=selective \
        ANSIBLE_NOCOLOR=true \
        ANSIBLE_RETRY_FILES_ENABLED=false \
        VAULT_PASS="${VAULT_PASS}" \
        NETBOX_TOKEN="${NETBOX_TOKEN}" \
        NETBOX_URL="${NETBOX_URL}" \
        ansible-playbook "${ANSIBLE_PATH}/import.yml" \
        -e "common_name=${common_name}" \
        -e "cert_file=${tmp_file}" > /dev/null 2>&1

    exit_code=$?

else

    cat <<EOF
╻┏┳┓┏━┓┏━┓┏━┓╺┳╸   ┏━┓┏━┓╻
┃┃┃┃┣━┛┃ ┃┣┳┛ ┃ ╺━╸┗━┓┗━┓┃
╹╹ ╹╹  ┗━┛╹┗╸ ╹    ┗━┛┗━┛┗━╸

EOF

    ANSIBLE_STDOUT_CALLBACK=selective \
        ANSIBLE_NOCOLOR=true \
        ANSIBLE_RETRY_FILES_ENABLED=false \
        VAULT_PASS="${VAULT_PASS}" \
        NETBOX_TOKEN="${NETBOX_TOKEN}" \
        NETBOX_URL="${NETBOX_URL}" \
        ansible-playbook "${ANSIBLE_PATH}/import.yml" \
        -i "${ANSIBLE_PATH}/inventory" \
        -e "common_name=${common_name}" \
        -e "cert_file=${tmp_file}" | sed 's/^[[:space:]]*//'

    exit_code=$?

fi

exit ${exit_code}
