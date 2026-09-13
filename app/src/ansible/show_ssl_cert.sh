#!/bin/bash
#
# show_ssl_cert.sh - wanportal SSL record viewer.
#
# Not a playbook wrapper: the wanportal API passes the record payload as $1
# (common_name, ssl_privkey {privkey, passphrase}, ssl_certificate) and this
# script decrypts privkey and passphrase with the ansible-vault CLI when they
# start with $ANSIBLE_VAULT, using vault id netops and the secret from
# VAULT_PASS. Prints everything as JSON when HTTP_ACCEPT wants it, otherwise a
# banner plus plain text sections. Read only, always exits 0.
#
# Needs jq, the ansible-vault CLI (from the ansible package) and VAULT_PASS.

export ANSIBLE_HOME=/tmp/.ansible-$(id -u)
export ANSIBLE_LOCAL_TEMP=/tmp/.ansible-$(id -u)/tmp
export ANSIBLE_REMOTE_TEMP=/tmp/.ansible-$(id -u)/tmp

common_name=$(jq -r '.common_name' <<< "${1}")
privkey=$(jq -r '.ssl_privkey.privkey // empty' <<< "${1}" | sed 's/\\n/\n/g')
passphrase=$(jq -r '.ssl_privkey.passphrase // empty' <<< "${1}" | sed 's/\\n/\n/g')
certificate=$(jq -r '.ssl_certificate // empty' <<< "${1}")

# Decrypt if vault encrypted
if echo "${privkey}" | grep -q '^\$ANSIBLE_VAULT'; then
    privkey=$(ansible-vault decrypt --vault-id netops@<(echo -n "${VAULT_PASS}") <<< "${privkey}" 2>/dev/null)
    if [[ $? -ne 0 ]]; then
        privkey="ERROR: Failed to decrypt private key"
    fi
fi

if echo "${passphrase}" | grep -q '^\$ANSIBLE_VAULT'; then
    passphrase=$(ansible-vault decrypt --vault-id netops@<(echo -n "${VAULT_PASS}") <<< "${passphrase}" 2>/dev/null)
    if [[ $? -ne 0 ]]; then
        passphrase="ERROR: Failed to decrypt passphrase"
    fi
fi

# Output based on Accept header
if echo "${HTTP_ACCEPT}" | grep -qE 'application/json|\*/\*'; then
    jq -n \
        --arg common_name  "${common_name}" \
        --arg certificate  "${certificate}" \
        --arg privkey      "${privkey}" \
        --arg passphrase   "${passphrase}" \
        '{
            action:      "show",
            common_name: $common_name,
            certificate: $certificate,
            privkey:     $privkey,
            passphrase:  $passphrase
        }'
else
    cat <<EOF
┏━┓╻ ╻┏━┓╻ ╻   ┏━┓┏━┓╻
┗━┓┣━┫┃ ┃┃╻┃╺━╸┗━┓┗━┓┃
┗━┛╹ ╹┗━┛┗┻┛   ┗━┛┗━┛┗━╸

common_name: ${common_name}
passphrase: ${passphrase:-Not found}

###################
### CERTIFICATE ###
###################

${certificate:-Not found}

###################
### PRIVATE KEY ###
###################

${privkey:-Not found}
EOF
fi

exit 0
