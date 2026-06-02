# Determine SSH hostname (GitHub requires ssh.github.com on port 443)
SSH_HOST="{{ $host }}"
if [ "$SSH_HOST" = "github.com" ]; then
    SSH_HOST="ssh.github.com"
fi

echo "Host {{ $host }}-{{ $key }}
        Port 443
        Hostname $SSH_HOST
        IdentityFile=~/.ssh/{{ $key }}" >> ~/.ssh/config

chmod 600 ~/.ssh/config

ssh-keyscan -p 443 -H $SSH_HOST >> ~/.ssh/known_hosts

rm -rf {{ $path }}

if ! git config --global core.fileMode false; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

if ! git clone -b {{ $branch }} {{ $repo }} {{ $path }}; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

if ! find {{ $path }} -type d -exec chmod 755 {} \;; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

if ! find {{ $path }} -type f -exec chmod 644 {} \;; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

if ! cd {{ $path }} && git config core.fileMode false; then
    echo 'VITO_SSH_ERROR' && exit 1
fi
