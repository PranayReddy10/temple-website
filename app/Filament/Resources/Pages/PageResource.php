<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use App\Support\Seo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The website's policy and information pages: privacy policy, terms,
 * refund and cancellation, account deletion, about, contact and any others.
 * Shown at darshansaathi.com/{slug} and linked in every page's footer.
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Pages';

    protected static ?string $slug = 'website/pages';

    /** Legal text: super admins only. */
    public static function canAccess(): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('title')->required()->maxLength(160),
                TextInput::make('slug')->label('Address')->required()->maxLength(80)
                    ->prefix(fn () => preg_replace('#^https?://#', '', Seo::website()).'/')
                    ->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')
                    ->rule(Rule::notIn(Page::RESERVED))
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?Page $record) => $record !== null && in_array($record->slug, Page::REQUIRED, true))
                    ->dehydrated(fn (?Page $record) => ! ($record !== null && in_array($record->slug, Page::REQUIRED, true)))
                    ->helperText(fn (?Page $record) => $record !== null && in_array($record->slug, Page::REQUIRED, true)
                        ? 'Fixed: the app, the payment gateways and the stores link to this address.'
                        : 'Lower-case letters, numbers and dashes.'),
                Textarea::make('summary')->label('Search description')->rows(2)->maxLength(300)->columnSpanFull()
                    ->helperText('What Google shows under the title. About 150 characters.'),
                RichEditor::make('body')->label('Text')->columnSpanFull()
                    ->toolbarButtons([['bold', 'italic', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']])
                    ->helperText('These words are filled in when the page is shown: {app}, {website}, {email}, {business}, {address}, {grievance_officer}, {courts}. Set them under Website → Business details and Administration → Settings.'),
                Toggle::make('is_published')->label('Published')->default(true),
                Toggle::make('show_in_footer')->label('Link in the website footer')->default(true),
                TextInput::make('sort_order')->label('Order in the footer')->numeric()->default(50),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('title')->weight('medium')->searchable()
                    ->description(fn (Page $r) => '/'.$r->slug),
                IconColumn::make('is_published')->label('Published')->boolean(),
                IconColumn::make('show_in_footer')->label('In footer')->boolean(),
                TextColumn::make('updated_at')->label('Last updated')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('view')->label('Open')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Page $r) => $r->url(), shouldOpenInNewTab: true),
                EditAction::make(),
                DeleteAction::make()->hidden(fn (Page $r) => in_array($r->slug, Page::REQUIRED, true)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }
}
