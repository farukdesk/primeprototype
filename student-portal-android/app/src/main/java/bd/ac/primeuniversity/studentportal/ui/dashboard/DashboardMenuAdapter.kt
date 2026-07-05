package bd.ac.primeuniversity.studentportal.ui.dashboard

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.core.graphics.ColorUtils
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.databinding.ItemDashboardMenuBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemDashboardSectionBinding

/**
 * Renders the grouped dashboard launcher: section headers interleaved with
 * tappable feature rows. [onClick] fires with the selected [Feature].
 */
class DashboardMenuAdapter(
    private val rows: List<MenuRow>,
    private val onClick: (Feature) -> Unit,
) : RecyclerView.Adapter<RecyclerView.ViewHolder>() {

    override fun getItemCount(): Int = rows.size

    override fun getItemViewType(position: Int): Int = when (rows[position]) {
        is MenuRow.Header -> TYPE_HEADER
        is MenuRow.Item -> TYPE_ITEM
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
        val inflater = LayoutInflater.from(parent.context)
        return if (viewType == TYPE_HEADER) {
            HeaderHolder(ItemDashboardSectionBinding.inflate(inflater, parent, false))
        } else {
            ItemHolder(ItemDashboardMenuBinding.inflate(inflater, parent, false))
        }
    }

    override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
        when (val row = rows[position]) {
            is MenuRow.Header -> (holder as HeaderHolder).bind(row)
            is MenuRow.Item -> (holder as ItemHolder).bind(row.feature)
        }
    }

    inner class HeaderHolder(
        private val binding: ItemDashboardSectionBinding,
    ) : RecyclerView.ViewHolder(binding.root) {
        fun bind(header: MenuRow.Header) {
            binding.sectionTitle.setText(header.titleRes)
        }
    }

    inner class ItemHolder(
        private val binding: ItemDashboardMenuBinding,
    ) : RecyclerView.ViewHolder(binding.root) {
        fun bind(feature: Feature) {
            val context = binding.root.context
            binding.menuTitle.setText(feature.titleRes)
            binding.menuIcon.setImageResource(feature.iconRes)

            val accent = ContextCompat.getColor(context, feature.colorRes)
            binding.menuIcon.setColorFilter(accent)
            binding.iconContainer.background?.mutate()
                ?.setTint(ColorUtils.setAlphaComponent(accent, 30))

            if (feature.subtitleRes != 0) {
                binding.menuSubtitle.setText(feature.subtitleRes)
                binding.menuSubtitle.visibility = android.view.View.VISIBLE
            } else {
                binding.menuSubtitle.visibility = android.view.View.GONE
            }

            binding.root.setOnClickListener { onClick(feature) }
        }
    }

    companion object {
        private const val TYPE_HEADER = 0
        private const val TYPE_ITEM = 1
    }
}
