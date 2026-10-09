<td>{{ $row->title }}</td><td>{{ $row->classroom?->name ?: 'عام' }}</td><td>{{ $row->activity_date?->format('Y-m-d') }}</td><td>{{ $row->status }}</td>
