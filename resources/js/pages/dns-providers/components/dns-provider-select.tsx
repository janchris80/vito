import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import React from 'react';
import { SelectTriggerProps } from '@radix-ui/react-select';
import { DNSProvider } from '@/types/dns-provider';

export default function DNSProviderSelect({
  value,
  onValueChange,
  providerFilter,
  ...props
}: {
  value: string;
  onValueChange: (value: string) => void;
  providerFilter?: string;
} & SelectTriggerProps) {
  const query = useQuery<DNSProvider[]>({
    queryKey: ['dnsProviders'],
    queryFn: async () => {
      const response = await axios.get(route('dns-providers.json'));
      return response.data;
    },
  });

  const providers = query.isSuccess 
    ? (providerFilter ? query.data.filter(p => p.provider === providerFilter) : query.data)
    : [];

  return (
    <Select value={value} onValueChange={onValueChange} disabled={query.isFetching}>
      <SelectTrigger {...props}>
        <SelectValue placeholder={query.isFetching ? 'Loading...' : 'Select an account'} />
      </SelectTrigger>
      <SelectContent>
        <SelectGroup>
          {providers.map((dnsProvider: DNSProvider) => (
            <SelectItem key={dnsProvider.id} value={dnsProvider.id.toString()}>
              {dnsProvider.name}
            </SelectItem>
          ))}
        </SelectGroup>
      </SelectContent>
    </Select>
  );
}
