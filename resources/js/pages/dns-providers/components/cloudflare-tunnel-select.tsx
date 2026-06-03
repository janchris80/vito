import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import React from 'react';
import { SelectTriggerProps } from '@radix-ui/react-select';

interface Tunnel {
  id: string;
  name: string;
  account_id: string;
  account_name: string;
  status: string;
}

export default function CloudflareTunnelSelect({
  value,
  onValueChange,
  dnsProviderId,
  onAccountIdChange,
  ...props
}: {
  value: string;
  onValueChange: (value: string) => void;
  dnsProviderId: string;
  onAccountIdChange?: (accountId: string) => void;
} & SelectTriggerProps) {
  const query = useQuery<Tunnel[]>({
    queryKey: ['cloudflareTunnels', dnsProviderId],
    queryFn: async () => {
      if (!dnsProviderId) return [];
      const response = await axios.get(route('dns-providers.tunnels', { dnsProvider: dnsProviderId }));
      return response.data;
    },
    enabled: !!dnsProviderId,
  });

  React.useEffect(() => {
    if (query.isSuccess && value && onAccountIdChange) {
      const tunnel = query.data.find(t => t.id === value);
      if (tunnel) {
        onAccountIdChange(tunnel.account_id);
      }
    }
  }, [query.isSuccess, query.data, value, onAccountIdChange]);

  return (
    <Select value={value} onValueChange={(val) => {
        onValueChange(val);
        if (onAccountIdChange && query.isSuccess) {
            const tunnel = query.data.find(t => t.id === val);
            if (tunnel) onAccountIdChange(tunnel.account_id);
        }
    }} disabled={query.isFetching || !dnsProviderId}>
      <SelectTrigger {...props}>
        <SelectValue placeholder={!dnsProviderId ? 'Select an account first' : (query.isFetching ? 'Loading tunnels...' : 'Select a tunnel')} />
      </SelectTrigger>
      <SelectContent>
        <SelectGroup>
          {query.isSuccess && query.data.map((tunnel: Tunnel) => (
            <SelectItem key={tunnel.id} value={tunnel.id}>
              {tunnel.name} ({tunnel.account_name})
            </SelectItem>
          ))}
          {query.isSuccess && query.data.length === 0 && (
            <div className="p-2 text-sm text-muted-foreground">No tunnels found in this account.</div>
          )}
        </SelectGroup>
      </SelectContent>
    </Select>
  );
}
