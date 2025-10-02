# Membership Tier Configuration Design Document

## 1. Overview

This document outlines the design for a WordPress plugin that implements a membership tier configuration system with player account-based pricing for Paid Memberships Pro. The plugin will provide four distinct membership tier configurations that map to existing PMPro membership levels, each with varying features and pricing models:

1. **"Gelimiteerd [Proef]"** - A limited trial membership with 1 player account, no ability to add more accounts
2. **"Gelimiteerd"** - A limited membership with 1 player account, no ability to add more accounts
3. **"Standaard"** - A default membership with 2 player accounts by default, allowing up to 10 additional accounts at €1.50 each
4. **"Plus"** - A premium membership with 2 player accounts by default, allowing unlimited accounts with a cap at 25 (no charge for accounts beyond 25), including collective invoice functionality

The plugin will integrate with PMPro's existing proration system for mid-cycle account adjustments and support both Stripe and Pay-by-Check payment methods, with special handling for collective invoices.

## 2. Architecture

The plugin follows a modular architecture with the following components:

- **bb-pmpro-player-account-based-subscriptions.php**: Bootstrap file that initializes the plugin
- **includes/class-membership-tier-manager.php**: Core logic for managing membership tiers
- **includes/class-checkout-handler.php**: Handles checkout processing
- **includes/class-proration-handler.php**: Manages billing logic for proration
- **includes/class-stripe-integration.php**: Integrates with Stripe payment processing
- **includes/class-collective-invoice-handler.php**: Manages collective invoice functionality
- **includes/class-pay-by-check-handler.php**: Integrates with Pay-by-Check payment method
- **includes/admin-settings.php**: Admin interface components
- **includes/rest-endpoints.php**: REST API endpoint definitions
- **assets/js/membership-tier.js**: Frontend JavaScript logic
- **assets/css/membership-tier.css**: Styling for the plugin
- **languages/pmpro-membership-tiers-nl_NL.mo**: Dutch translation files

### 2.1 Design Principles

- **DRY (Don't Repeat Yourself)**: Shared functionality is encapsulated in reusable classes
- **SOLID Principles**: 
  - Single Responsibility Principle: Each class has a single purpose
  - Open/Closed Principle: Classes are open for extension but closed for modification
  - Liskov Substitution Principle: Derived classes can be substituted for base classes
  - Interface Segregation Principle: Clients only depend on methods they use
  - Dependency Inversion Principle: High-level modules don't depend on low-level modules

### 2.2 Technology Stack

- **Backend**: PHP 7.1+ with WordPress Plugin API
- **Frontend**: Vanilla JavaScript + jQuery, CSS
- **Dependencies**: 
  - `php-di/php-di ^7.1` (Dependency Injection)
  - Paid Memberships Pro plugin
  - PMPro Proration Add-on
  - PMPro Group Accounts Add-on
  - PMPro Pay by Check Add-on
  - Stripe PHP SDK (via PMPro)

## 3. Component Architecture

### 3.0 Implementation Patterns from Integrated Plugins

The design will leverage several proven patterns from the integrated PMPro plugins:

1. **Per-Level Configuration Storage**: Following the PMPro Group Accounts pattern, configuration settings will be stored as serialized arrays in WordPress options with keys like `pmpro_player_accounts_level_{id}`

2. **Feature Flag System**: Using the existing comma-separated string pattern for features, allowing easy addition of new functionality without database schema changes

3. **Gateway Integration**: Following the PMPro Pay by Check pattern, custom payment flows will be implemented as gateway extensions that integrate with the existing PMPro payment system

4. **Subscription Management**: Leveraging the PMPro Proration and Group Accounts patterns for handling subscription modifications, including delayed changes and prorated billing

5. **Admin UI Integration**: Following PMPro's section-based approach for adding settings to the membership level edit page with collapsible sections

6. **Meta Data Storage**: Using PMPro's membership order and user meta systems for storing account counts and related information

7. **Hook-Based Extensibility**: Implementing WordPress actions and filters to allow customization of behavior without modifying core code

8. **Custom Payment Method**: Implementing Collective Invoice as a custom PMPro gateway following the extension patterns used by Pay by Check

### 3.1 Core Components

#### 3.1.1 Membership Tier Manager (`class-membership-tier-manager.php`)
Central class responsible for:
- Managing tier-specific configurations for PMPro membership levels
- Handling feature flags for each tier (using the existing comma-separated pattern)
- Providing helper methods to check for specific features
- Managing per-level settings following the existing plugin pattern
- Coordinating interactions between components
- Mapping PMPro membership levels to tier configurations

#### 3.1.2 Checkout Handler (`class-checkout-handler.php`)
Responsible for:
- Rendering player account selection fields based on membership tier
- Validating user input during checkout
- Calculating additional costs based on selected accounts
- Integrating with PMPro checkout flow

#### 3.1.3 Proration Handler (`class-proration-handler.php`)
Handles:
- Mid-cycle account adjustments
- Proration calculations for account changes
- Integration with PMPro Proration Add-on

#### 3.1.4 Stripe Integration (`class-stripe-integration.php`)
Manages:
- Stripe subscription creation and management
- Dynamic product and price creation
- Invoice generation and management
- Integration with existing PMPro Stripe functionality

#### 3.1.5 Collective Invoice Handler (`class-collective-invoice-handler.php`)
Specialized component for:
- Creating collective invoices for organization admins
- Managing group account subscriptions
- Generating consolidated invoices
- Integration with PMPro Group Accounts Add-on

#### 3.1.6 Pay-by-Check Handler (`class-pay-by-check-handler.php`)
Handles:
- Pay-by-check payment flow
- Invoice generation for check payments
- Integration with PMPro Pay-by-Check Add-on

### 3.2 Data Models

#### 3.2.1 Membership Tier Configuration

The MembershipTier configuration will be stored per PMPro membership level using the existing pattern from the integrated plugins:

```php
// Example configuration stored in option: pmpro_player_accounts_level_3
$level_config = array(
    'default_accounts' => 2,
    'price_per_account' => 1.5,
    'max_accounts' => 10,
    'proration_enabled' => 1,
    'allow_extra_accounts' => 1,
    'features' => 'STANDARD_ACCESS, ACCOUNT_LIMIT_10, EXTRA_ACCOUNTS'
);
```

Configuration properties:
- **default_accounts**: Number of accounts included by default
- **price_per_account**: Cost per additional account
- **max_accounts**: Maximum accounts allowed for this tier
- **proration_enabled**: Whether proration is enabled for this tier (following the existing plugin pattern)
- **allow_extra_accounts**: Whether extra accounts can be purchased (following the existing plugin pattern)
- **features**: Comma-separated string of features available for this tier

Since we are working with existing PMPro membership levels, the name and identifier are inherited from the PMPro level itself. The configuration is stored as a serialized array in WordPress options with keys following the pattern `pmpro_player_accounts_level_{level_id}`.

#### 3.2.2 Tier Configurations

The plugin will implement four membership tier configurations with the following properties and feature flags:

1. **Gelimiteerd [Proef]** (Limited Trial)
   - Default Accounts: 1
   - Price per Account: €0.00
   - Max Accounts: 1
   - Features: `LIMITED_ACCESS`, `TRIAL_MEMBERSHIP`
   - Proration: Enabled by default

2. **Gelimiteerd** (Limited)
   - Default Accounts: 1
   - Price per Account: €0.00
   - Max Accounts: 1
   - Features: `LIMITED_ACCESS`
   - Proration: Enabled by default

3. **Standaard** (Standard)
   - Default Accounts: 2
   - Price per Account: €1.50
   - Max Accounts: 10
   - Features: `STANDARD_ACCESS`, `ACCOUNT_LIMIT_10`, `EXTRA_ACCOUNTS`
   - Proration: Enabled by default

4. **Plus** (Premium)
   - Default Accounts: 2
   - Price per Account: €1.50
   - Max Accounts: Unlimited (capped at 25)
   - Features: `PREMIUM_ACCESS`, `UNLIMITED_ACCOUNTS`, `ACCOUNT_LIMIT_25`, `EXTRA_ACCOUNTS`, `COLLECTIVE_INVOICE`
   - Proration: Enabled by default

Each tier will support the following features based on the integrated plugins:

- **Proration Support**: Integration with PMPro Proration for mid-cycle account adjustments
- **Custom Payment Method**: Implementation of Collective Invoice as a custom payment method
- **Multiple Payment Methods**: Support for Stripe, Pay-by-Check, and Collective Invoice payment flows
- **Admin Management**: Custom admin pages for managing collective invoices following the PMPro UI patterns
- **REST API**: Extension of the existing PMPro REST API with player account endpoints
- **Frontend Integration**: Dynamic JavaScript form updates following the existing plugin patterns

The collective invoice functionality will work by:
1. Organization administrators select Collective Invoice payment method during checkout
2. Admin creates list of members with email, subscription, and account counts
3. System calculates total cost and creates Stripe invoice
4. Stripe invoice is sent to organization admin for payment
5. System sends membership claim links to all listed email addresses
6. Members claim memberships using links without individual payment

## 4. API Endpoints Reference

### 4.1 REST API Endpoints

#### Get Membership Tier Details
- **Endpoint**: `GET /wp-json/pmpro/v1/membership-tiers/{level_id}`
- **Description**: Returns detailed information about a membership tier including pricing, features, and account limits.

#### Get User's Current Tier
- **Endpoint**: `GET /wp-json/pmpro/v1/user-tier`
- **Description**: Returns the current membership tier information for the authenticated user.

#### Update Player Accounts
- **Endpoint**: `PATCH /wp-json/pmpro/v1/player-accounts`
- **Description**: Updates the number of player accounts for the authenticated user (with proration if applicable).

#### Create Collective Invoice
- **Endpoint**: `POST /wp-json/pmpro/v1/collective-invoice`
- **Description**: Creates a collective invoice for an organization admin to pay for multiple memberships.

### 4.2 Authentication Requirements

All endpoints require authentication via WordPress cookie authentication or OAuth tokens. Administrative endpoints require `edit_users` capability.

## 5. Business Logic Layer

### 5.1 Membership Tier Logic

#### 5.1.1 Tier Configuration Mapping
Each membership level in PMPro will be mapped to one of the four defined tier configurations based on level ID or custom metadata stored in the existing PMPro level settings.

#### 5.1.2 Account Limit Enforcement
The system will enforce account limits based on the membership tier features:
- Tiers with `LIMITED_ACCESS` feature: Only 1 account allowed
- Tiers with `ACCOUNT_LIMIT_10` feature: Maximum 10 accounts
- Tiers with `ACCOUNT_LIMIT_25` feature: Maximum 25 accounts with no additional charge beyond that limit
- Tiers without `EXTRA_ACCOUNTS` feature: No additional accounts allowed beyond default
- Proration is enabled by default for all tiers but can be disabled per level following the existing plugin pattern

#### 5.1.3 Pricing Calculation
Pricing will be calculated as follows:
- Base membership price (from PMPro level)
- Additional account cost = (selected_accounts - default_accounts) × price_per_account
- For tiers with `ACCOUNT_LIMIT_25` feature: No charge for accounts beyond 25

### 5.2 Checkout Flow

#### 5.2.1 Implementation Patterns

The checkout flow will follow established patterns from the integrated plugins:

1. **Dynamic Form Rendering**: Following the PMPro Pay by Check pattern, the player account selection field will be dynamically rendered based on the selected membership level

2. **Real-time Cost Calculation**: Using JavaScript to update the total cost as users adjust their account selections, similar to how discount codes work in PMPro Proration

3. **Gateway Integration**: Integrating with Stripe, Pay-by-Check, and Collective Invoice payment methods following the patterns established in the respective plugins

4. **Order Modification**: Modifying the PMPro order object to include additional account costs before payment processing

5. **Metadata Storage**: Storing account information in user meta and order meta following the existing plugin patterns

6. **Custom Payment Method**: Implementing Collective Invoice as a custom payment gateway following PMPro gateway extension patterns

#### 5.2.1 Frontend Logic
1. User selects a membership level
2. JavaScript detects the associated tier
3. Player account selection field is rendered with appropriate constraints
4. Real-time cost calculation updates the total
5. Form validation ensures account limits are respected

#### 5.2.2 Backend Processing
1. Validate selected accounts against tier limits
2. Calculate additional costs
3. Modify PMPro order with extra costs
4. Process payment via selected gateway
5. Store account information in user meta

### 5.3 Proration Logic

#### 5.3.1 Account Adjustment Calculation
When a user changes their account count mid-cycle:
1. Calculate difference between current and new account count
2. Determine days remaining in billing period
3. Compute proration amount: account_difference × price_per_account × (days_remaining / days_in_period)
4. Apply proration to next billing cycle

#### 5.3.2 Integration with PMPro Proration
The plugin will hook into PMPro's proration system to ensure compatibility and consistency.

### 5.4 Collective Invoice Logic

#### 5.4.1 Organization Admin Workflow
1. Organization admin with Plus membership selects Collective Invoice payment method
2. Admin creates list of members with email, subscription, and account counts
3. System calculates total cost and displays for confirmation
4. Admin confirms and system creates Stripe invoice
5. Stripe invoice is sent to organization admin for payment
6. Upon payment, system sends membership claim links to all listed email addresses

#### 5.4.2 Invoice Generation
1. Create Stripe customer for organization
2. Generate consolidated invoice with line items for each member
3. Store invoice details in custom post type or options
4. Provide payment link to organization admin
5. Generate unique claim codes for each member email
6. Track payment status and membership claim status

## 6. Frontend Component Architecture

### 6.0 Admin Interface Patterns

The admin interface will follow patterns from the integrated plugins:

1. **Section-based Layout**: Using collapsible sections in the membership level edit page following the PMPro Group Accounts pattern

2. **Setting Validation**: Implementing proper validation and sanitization of settings following WordPress and PMPro standards

3. **Custom Admin Pages**: Creating dedicated admin pages for collective invoice management following the PMPro UI patterns

4. **Bulk Actions**: Supporting bulk operations for managing collective invoices

5. **Status Indicators**: Using visual indicators for invoice status (pending, active, paid) following WordPress admin conventions

### 6.1 Player Account Selection Component

#### 6.1.1 Component Definition
A dynamic input field that allows users to select the number of player accounts based on their membership tier.

#### 6.1.2 Props/State Management
- `tier`: Current membership tier information
- `defaultAccounts`: Number of accounts included by default
- `maxAccounts`: Maximum accounts allowed for this tier
- `pricePerAccount`: Cost per additional account
- `currentAccounts`: Currently selected number of accounts
- `totalCost`: Calculated total cost for additional accounts

#### 6.1.3 Lifecycle Methods
- On mount: Determine tier and set constraints
- On change: Validate input and update total cost
- On submit: Validate final selection before checkout

### 6.2 Collective Invoice Component

#### 6.2.1 Component Definition
A specialized interface for organization admins to manage group memberships and generate collective invoices.

#### 6.2.2 Props/State Management
- `isAdmin`: Boolean indicating if user has admin privileges
- `members`: Array of group members with account allocations
- `totalCost`: Consolidated cost for all members
- `invoiceStatus`: Current status of collective invoice

#### 6.2.3 Lifecycle Methods
- On mount: Load existing group members
- On add member: Validate email and add to members list
- On update accounts: Recalculate total cost
- On generate invoice: Create and store collective invoice

## 7. Data Flow Between Layers

### 7.1 Checkout Process Flow

The checkout process follows these steps:

1. User selects a membership level
2. Frontend requests tier information from backend
3. Backend returns tier configuration
4. Frontend renders account selection field with appropriate constraints
5. User adjusts account count
6. Frontend calculates real-time cost
7. User submits checkout form
8. Frontend sends form data to backend
9. Backend validates account selection against tier limits
10. Backend calculates additional costs
11. Backend modifies PMPro order with extra costs
12. PMPro processes payment through Stripe
13. Stripe returns payment result to PMPro
14. PMPro confirms order completion to backend
15. Backend stores account information
16. Backend returns success response to frontend
17. Frontend shows confirmation to user

### 7.2 Proration Process Flow

The proration process follows these steps:

1. User requests account count change
2. Backend validates new account count against tier limits
3. Backend requests proration calculation from PMPro Proration
4. PMPro Proration calculates proration amount
5. Backend applies proration to Stripe subscription
6. Stripe confirms proration
7. Backend updates user account metadata
8. Backend confirms update to user

### 7.3 Collective Invoice Flow

The collective invoice process follows these steps:

1. Admin with Plus membership selects Collective Invoice payment method
2. Admin creates list of members with email, subscription, and account counts
3. Backend calculates total cost and creates consolidated invoice through Stripe
4. Stripe returns invoice details
5. Backend stores invoice information and generates unique claim codes
6. Backend returns invoice payment link to admin
7. Admin pays invoice through Stripe
8. Stripe confirms payment to backend
9. Backend sends membership claim links to all member emails
10. Members claim memberships using links without individual payment
11. System tracks payment status and membership claim status

## 7. Database Schema

### 7.1 Metadata Storage

Following the patterns established in the integrated plugins, the plugin will use WordPress metadata systems:

1. **User Meta**:
   - `pmpro_player_accounts`: Current number of player accounts for a user

2. **Order Meta**:
   - `pmpro_player_accounts`: Accounts purchased in this order
   - `pmpro_player_accounts_extra_cost`: Additional annual cost
   - `pmpro_player_accounts_membership_id`: Associated membership level
   - `stripe_subscription_id`: Stripe subscription ID
   - `stripe_invoice_id`: Stripe invoice ID
   - `stripe_invoice_hosted_url`: Payment URL for customers

3. **WordPress Options**:
   - `pmpro_player_accounts_options`: Global plugin settings
   - `pmpro_player_accounts_level_{id}`: Per-level settings (includes features)
   - `pmpro_stripe_price_for_level_{id}`: Cached Stripe price IDs
   - `pmpro_stripe_price_extra_account_for_level_{id}`: Cached extra account prices
   - `pmpro_collective_invoice_{id}`: Collective invoice data

4. **Custom Payment Method Data**:
   - Collective invoice member lists with email, subscription, and account counts
   - Unique claim codes for membership redemption
   - Payment and claim status tracking

### 7.2 Custom Post Types

For collective invoices, a custom post type will be used following WordPress conventions, with metadata stored in post meta.

## 9. Security Considerations

### 8.1 Input Validation
- All user inputs are validated and sanitized
- Account count limits are enforced both frontend and backend
- Nonce verification for all form submissions

### 8.2 Access Control
- Capability checks for administrative functions
- User can only modify their own account information
- Organization admins can only manage their group members

### 8.3 Data Protection
- Sensitive data is properly escaped before output
- Database queries use WordPress meta APIs to prevent injection
- Stripe API keys are handled securely through PMPro

## 10. Internationalization

### 9.1 Dutch Translation
The plugin will include Dutch translation files:
- `pmpro-membership-tiers-nl_NL.po`
- `pmpro-membership-tiers-nl_NL.mo`

### 9.2 Translatable Strings
All user-facing strings will be properly internationalized using WordPress i18n functions.

## 11. Testing Strategy

### 10.1 Unit Testing
- Membership tier identification and validation
- Account limit enforcement
- Pricing calculation accuracy
- Proration calculation correctness
- Collective invoice generation

### 10.2 Integration Testing
- PMPro integration
- Stripe payment processing
- Proration add-on compatibility
- Group accounts add-on integration
- Pay-by-check functionality

### 10.3 User Acceptance Testing
- Membership selection workflow
- Account adjustment process
- Collective invoice creation
- Payment processing flows
- Admin management interfaces